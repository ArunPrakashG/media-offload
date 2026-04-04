package license

import (
	"net"
	"net/url"
	"strings"
)

// NormalizeDomain reduces a raw URL or hostname to a canonical lowercase hostname.
//
//	"https://WWW.Example.com/some/path" → "example.com"
//	"http://store.example.com"          → "store.example.com"
//	"192.168.1.1"                       → "192.168.1.1"
func NormalizeDomain(raw string) string {
	raw = strings.TrimSpace(raw)
	if raw == "" {
		return ""
	}
	// Ensure a scheme so url.Parse interprets the host correctly.
	if !strings.Contains(raw, "://") {
		raw = "https://" + raw
	}
	u, err := url.Parse(raw)
	if err != nil {
		return strings.ToLower(raw)
	}
	host := strings.ToLower(u.Hostname()) // strips port
	host = strings.TrimPrefix(host, "www.")
	host = strings.TrimSuffix(host, ".") // strip FQDN trailing dot
	// IP addresses are returned as-is.
	if net.ParseIP(host) != nil {
		return host
	}
	return host
}
