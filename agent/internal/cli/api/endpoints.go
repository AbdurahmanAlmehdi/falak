package api

import (
	"net/url"
	"strings"
)

// Public REST API endpoints (control plane, Sanctum bearer token; tokens are pinned to one
// organization). The API is still being built: keep every path here so it is easy to adjust.
// Placeholders {name} are filled (path-escaped) by Path.
const (
	PathMe            = "/api/v1/me"            // GET  → {data:{user, organization, token}}
	PathOrganizations = "/api/v1/organizations" // GET  → {data:[Organization]} (falls back to /me)

	PathServers     = "/api/v1/servers"                // GET  → {data:[Server]}
	PathServer      = "/api/v1/servers/{server}"       // GET  → {data:Server}
	PathServerStack = "/api/v1/servers/{server}/stack" // POST {php?, node?} → 202 {data:StackChange}

	PathSites = "/api/v1/sites"        // GET  → {data:[Site]}
	PathSite  = "/api/v1/sites/{site}" // GET  → {data:Site} (id or slug)

	PathSiteDeployments  = "/api/v1/sites/{site}/deployments"        // POST {branch?} → 201 {data:Deployment}
	PathDeployment       = "/api/v1/deployments/{deployment}"        // GET  → {data:Deployment}
	PathDeploymentOutput = "/api/v1/deployments/{deployment}/output" // GET ?after=<seq> → {data:[OutputLine], meta:{next}}
	PathSiteRollback     = "/api/v1/sites/{site}/rollback"           // POST {release_id?} → 201 {data:Deployment}
	PathSiteReleases     = "/api/v1/sites/{site}/releases"           // GET  → {data:[Release]}
	PathSiteEnv          = "/api/v1/sites/{site}/env"                // GET → {data:{content}} ; PUT {content}
	PathSiteLogs         = "/api/v1/sites/{site}/logs"               // GET ?since=&limit=&level=&cursor= → {data:[LogEntry], meta:{cursor}}
)

// Path fills {placeholders} from name/value pairs.
func Path(tmpl string, kv ...string) string {
	for i := 0; i+1 < len(kv); i += 2 {
		tmpl = strings.ReplaceAll(tmpl, "{"+kv[i]+"}", url.PathEscape(kv[i+1]))
	}
	return tmpl
}
