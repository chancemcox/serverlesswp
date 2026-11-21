# Branches

- `main` — production. Deploys to apparelishoweyeroll.com. Only changes that are verified working on dev go here.
- `dev` — testing environment. Vercel builds a preview deployment for every push, and ServerlessWP automatically gives it its own database file (wp-sqlite-s3-dev.sqlite), separate from production.

Workflow: build and test big additions (new plugins, large file sets) on `dev` first. Once the dev deployment is verified working, apply the same change to `main`.
