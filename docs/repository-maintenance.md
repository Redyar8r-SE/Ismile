# Repository maintenance

## Source layout

| Location | Purpose |
| --- | --- |
| Root HTML pages | Public page markup and entry points |
| `css/`, `js/` | Shared styles, components, and page behavior |
| `data/` | Default website content, translations, and sponsor artwork references |
| `assets/` | Images and other files used by the website |
| `admin/`, `api/`, `backend/` | Admin interface, HTTP entry points, PHP application and database migrations |
| `server/`, `netlify/` | Node server and hosting adapters |
| `tests/`, `backend/tests/` | JavaScript and PHP verification |
| `scripts/`, `tools/` | Deployment and repository tooling |
| `docs/` | Maintained project documentation and reference designs |
| `.local/` | Ignored local release state, recovery archives, and temporary tooling |
| `output/` | Ignored generated previews, screenshots, and audit results |

Keep runtime code in its existing directory so imports, URLs, and deployment
paths remain stable. Publish selected artwork into `assets/` and reference it
from the appropriate content file. Generated browser profiles and caches do
not belong in Git. Retain design sources or recovery archives locally when
they are useful; do not publish a whole output folder.

## Working and publishing

`main` deploys the public Node website. `backend` deploys the PHP backend and
admin site. Check both branches when a change affects public and backend
behavior; do not replace one branch with the other.

Before editing, fetch the latest branch and inspect `git status`. A local
checkout can lag behind changes published from another checkout or release
tool. Compare those files before committing: a modified or untracked file can
already exist in GitHub. Preserve unique local work before updating. After a
release, fetch and update the relevant local branch so its status is accurate.

Deployment also uses persistent server content and database settings. Updating
the repository's default JSON is not always sufficient to update that content.
Merge only the requested content changes and preserve unrelated server edits.
Sponsor package counts are regenerated from booth settings; `displayPlaces`
in sponsor content controls presentation independently of physical capacity.

Run the standard JavaScript checks before publishing code:

```sh
npm run check-errors
```

PHP changes also need the relevant backend validation documented in
`backend/README.md`. Review staged changes with `git diff --cached`, commit the
intended files, and verify deployment and the live behavior after publishing.

## Cleanup

Keep currently referenced assets. Before deleting an old asset, search tracked
source and documentation for its filename and check current website content.
Remove unused drafts and generated caches after preserving any needed reference
material. Secrets, local environment files, database storage, dependency folders,
and local backups remain ignored; never add them with a broad force-add.
