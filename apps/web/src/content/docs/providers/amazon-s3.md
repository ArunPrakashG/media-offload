---
title: Connect Amazon S3
nav: Amazon S3
description: Create an IAM user and bucket on Amazon S3, enter the keys in the Nimbus setup wizard, test the connection and start offloading your WordPress media.
section: providers
order: 1
logo: s3
---

Amazon S3 is the default provider in Nimbus. You need a bucket, an IAM user with access to it, and that user's access key.

## 1. Create credentials

1. In the S3 console, create a bucket in the region you want. Note the region code, for example `eu-west-2`.
2. In IAM, create a user for Nimbus. Do not reuse your root account keys.
3. Attach a policy that allows only that bucket:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": ["s3:ListBucket"],
      "Resource": "arn:aws:s3:::YOUR-BUCKET"
    },
    {
      "Effect": "Allow",
      "Action": ["s3:PutObject", "s3:GetObject", "s3:DeleteObject"],
      "Resource": "arn:aws:s3:::YOUR-BUCKET/*"
    }
  ]
}
```

4. Create an access key for the user and copy both values. The secret is shown once.

Nimbus does not set object ACLs. For visitors to load files, either allow public reads with a bucket policy, or serve through CloudFront and enter its domain as the Custom Domain.

## 2. Fill in the setup wizard

Open **Media → Nimbus** and choose **Amazon S3**.

| Field | Where to find it |
| --- | --- |
| Bucket | The bucket name from the S3 console. |
| Region | AWS region code, for example `us-east-1`, `eu-west-2` or `ap-southeast-1`. |
| Access Key | The IAM Access Key ID. |
| Secret Key | The IAM Secret Access Key. |
| Session Token (optional) | Only for temporary STS credentials. |
| Path Prefix (optional) | A folder inside the bucket, for example `media`. |
| Custom Endpoint (optional) | Leave empty to use the standard AWS endpoint. Override only for VPC or custom setups. |
| Custom Domain (optional) | Your CloudFront or CDN domain, for example `https://cdn.example.com`. |

Session Token, Custom Endpoint and path-style options sit behind **Show advanced connection fields** on the Settings tab.

## 3. Test and offload

Open **Tools** and select **Test Connection**. A working setup reports that the connection succeeded and write access is verified. Then run **Offload existing library** under **Bulk media jobs**, or queue files from the command line:

```bash
wp nimbus sync --limit=500
wp nimbus process-pending --limit=500
```

`sync` marks attachments as pending. `process-pending` offloads them. See [bulk migration](/docs/guides/bulk-migration/) for details.

## Notes

- If you set a Custom Endpoint that is an R2 URL, Nimbus treats the connection as Cloudflare R2. Use the [Cloudflare R2](/docs/providers/cloudflare-r2/) page instead.
- Without a Custom Domain, URLs use the standard S3 object URL for the bucket and region.
- To put CloudFront in front, see [custom domain and CDN](/docs/guides/custom-domain/).

> **Tip:** Give the IAM user access to one bucket only. If the keys ever leak, the damage stays inside that bucket.
