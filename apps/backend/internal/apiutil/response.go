// Package apiutil contains lightweight HTTP response helpers shared by all
// handler sub-packages. It is a leaf package with no project imports so it
// can be safely imported by internal/api and its sub-packages alike without
// creating import cycles.
package apiutil

import (
	"encoding/json"
	"net/http"
)

type errorBody struct {
	Error string `json:"error"`
	Code  string `json:"code"`
}

// JSON writes a JSON-encoded response with the given HTTP status code.
func JSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}

// Error writes a standardised JSON error response.
func Error(w http.ResponseWriter, status int, code, message string) {
	JSON(w, status, errorBody{Error: message, Code: code})
}
