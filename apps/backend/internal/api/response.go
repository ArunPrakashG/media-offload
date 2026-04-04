package api

import (
	"net/http"

	"github.com/arunprakashg/nimbus-backend/internal/apiutil"
)

// JSON writes a JSON response with the given status code.
// Delegates to apiutil.JSON so handler sub-packages can use apiutil directly
// without creating an import cycle back to this package.
func JSON(w http.ResponseWriter, status int, v any) { apiutil.JSON(w, status, v) }

// Error writes a standardised JSON error response.
func Error(w http.ResponseWriter, status int, code, message string) {
	apiutil.Error(w, status, code, message)
}
