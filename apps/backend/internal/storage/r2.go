// Package storage provides a Cloudflare R2 client for generating presigned
// download URLs for the pro plugin zip.
//
// Cloudflare R2 is S3-compatible; the standard aws-sdk-go-v2 S3 presigner
// is used with a custom endpoint pointed at the R2 bucket.
//
// Object key convention:
//
//	releases/{product-slug}/{version}/nimbus-pro.zip
//	e.g. releases/nimbus-pro/0.2.0/nimbus-pro.zip
//
// The bucket is private. Every download requires a live license check performed
// upstream by the updates handler before the presigned URL is generated.
package storage

import (
	"context"
	"fmt"
	"time"

	"github.com/aws/aws-sdk-go-v2/aws"
	"github.com/aws/aws-sdk-go-v2/credentials"
	"github.com/aws/aws-sdk-go-v2/service/s3"
)

// R2Client generates presigned GET URLs for objects stored in a private
// Cloudflare R2 bucket.
type R2Client struct {
	presigner *s3.PresignClient
	bucket    string
	ttl       time.Duration
}

// NewR2Client constructs an R2Client.
//
//   - accountID      — Cloudflare account ID (used to build the endpoint URL)
//   - accessKeyID    — R2 API token key ID
//   - accessKeySecret — R2 API token secret
//   - bucket         — private bucket name
//   - presignTTL     — how long each presigned URL remains valid
func NewR2Client(accountID, accessKeyID, accessKeySecret, bucket string, presignTTL time.Duration) *R2Client {
	endpoint := fmt.Sprintf("https://%s.r2.cloudflarestorage.com", accountID)

	s3Client := s3.New(s3.Options{
		BaseEndpoint: aws.String(endpoint),
		// R2 does not use real AWS regions; "auto" is accepted and ignored.
		Region:      "auto",
		Credentials: credentials.NewStaticCredentialsProvider(accessKeyID, accessKeySecret, ""),
		// R2 requires path-style addressing for presigned URLs.
		UsePathStyle: true,
	})

	return &R2Client{
		presigner: s3.NewPresignClient(s3Client),
		bucket:    bucket,
		ttl:       presignTTL,
	}
}

// PresignGet returns a presigned HTTP GET URL for the given object key.
// The URL expires after the TTL configured on the client.
func (c *R2Client) PresignGet(ctx context.Context, objectKey string) (string, error) {
	out, err := c.presigner.PresignGetObject(ctx, &s3.GetObjectInput{
		Bucket: aws.String(c.bucket),
		Key:    aws.String(objectKey),
	}, s3.WithPresignExpires(c.ttl))
	if err != nil {
		return "", fmt.Errorf("presign R2 get object: %w", err)
	}
	return out.URL, nil
}
