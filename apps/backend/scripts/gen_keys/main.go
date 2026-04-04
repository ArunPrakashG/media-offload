// gen_keys generates an Ed25519 keypair and prints the base64-encoded values
// ready to paste into .env.
//
// Usage:
//
//	go run ./scripts/gen_keys
package main

import (
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"fmt"
	"os"
)

func main() {
	pub, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		fmt.Fprintf(os.Stderr, "error: %v\n", err)
		os.Exit(1)
	}

	fmt.Printf("LICENSE_PRIVATE_KEY=%s\n", base64.StdEncoding.EncodeToString(priv))
	fmt.Printf("LICENSE_PUBLIC_KEY=%s\n", base64.StdEncoding.EncodeToString(pub))
}
