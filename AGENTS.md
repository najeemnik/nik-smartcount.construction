# Working on NiK Smart Count

- Read `DEVELOPMENT.md` and inspect current changes before editing. Preserve user work.
- Make targeted changes; do not rewrite working accounting, authentication or snapshot synchronization for a styling task.
- Enforce permissions, tenant and project boundaries in PHP. Client filtering is presentation, never the security boundary.
- Money has native-currency and normalized USD fields; do not divide normalized USD twice or lose native cents. Verify bill/payment/reversal totals and persistence after reload when relevant.
- The original React/TypeScript source is missing. `client/nsc-accounting-r3.js` is compiled editable JavaScript. Repack with `node tools/client-bundle.mjs pack`; keep its gzip synchronized and bump relevant asset URLs.
- Historical `.checks/patch-client-*` scripts target earlier bundle shapes. Do not run them as an unconditional build pipeline.
- Test only with isolated synthetic data; never seed or reset the hosted database. Preserve fixture database-name guards.
- Never commit credentials, private `.secrets` settings, signing keys, uploads, live database dumps or local test account files. Public creator/contact details in `about-profile.js` are intentional.
- Preserve original logo image bytes. Use `object-fit: contain`; honor reduced-motion preferences.
- Test meaningful affected scenarios in Dari/English and narrow/wide layouts where relevant. Do not claim “bug free” or security certification; report actual coverage and remaining device/service limits.
