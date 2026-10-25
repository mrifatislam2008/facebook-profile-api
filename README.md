# Facebook Profile Picture API (PHP + Vercel)

A small PHP API that asks `fvdownloader.net` for a profile-picture URL and can return either JSON metadata or image bytes. It does not log in to Facebook, bypass private profiles, or guarantee that the third-party provider remains available.

## Endpoints

Replace `YOUR-PROJECT.vercel.app` with your deployed Vercel domain.

- Health / usage: `https://YOUR-PROJECT.vercel.app/api/`
- JSON: `https://YOUR-PROJECT.vercel.app/api/?url=https%3A%2F%2Fwww.facebook.com%2Fexample&mode=json`
- Image: `https://YOUR-PROJECT.vercel.app/api/?url=https%3A%2F%2Fwww.facebook.com%2Fexample&mode=image`

`mode` defaults to `json`. Use URL-encoding when passing a Facebook URL. Replace the example profile URL with a public profile you are allowed to access.

## Files

```text
facebook-profile-api/
├── api/
│   └── index.php
├── vercel.json
├── README.md
└── .gitignore
```

## Deploy using GitHub + Vercel (phone-friendly)

1. Sign in to GitHub and create a new repository named `facebook-profile-api`.
2. Upload the `api` folder, `vercel.json`, `README.md`, and `.gitignore` from this project. Keep `index.php` inside `api/`.
3. Sign in to Vercel and choose **Add New → Project**.
4. Import the GitHub repository and click **Deploy**. The repository uses the community PHP runtime `vercel-php@0.9.0` (PHP 8.5); runtime support and plan limits may change.
5. Open the deployment URL and test the API endpoints above.
6. For future updates, edit the files in GitHub and commit the change. Vercel will redeploy the connected project automatically.

## Error responses

Errors are JSON with `success: false` and an `error` message. Common statuses: 400 invalid/missing input, 404 picture not found, 405 unsupported method, and 502 third-party/runtime failure.

## Notes

- The third-party endpoint and response format can change without notice. This project is not affiliated with Facebook or fvdownloader.net.
- The API only accepts HTTP(S) Facebook URLs from an explicit hostname allowlist; the returned image URL is required to be HTTPS and resolve to public IP addresses. Redirects are disabled for image retrieval.
- Do not add copied session cookies or secret tokens to source control. If a provider legitimately requires credentials, store them as Vercel Environment Variables and use only an authorized account/session.
- `Access-Control-Allow-Origin: *` is convenient for public demos but does not authenticate callers. Add rate limiting or an API key before public production use.
- Vercel community PHP runtime docs: https://github.com/vercel-community/php
