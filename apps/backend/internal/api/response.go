package api

import (
	"encoding/json"
	"net/http"
)

type errorBody struct {
	Error string `json:"error"`
	Code  string `json:"code"`
}

// JSON writes a JSON response with the given status code.
func JSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}

// Error writes a standardised JSON error response.
func Error(w http.ResponseWriter, status int, code, message string) {
	JSON(w, status, errorBody{Error: message, Code: code})
}
