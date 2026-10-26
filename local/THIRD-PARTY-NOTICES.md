# Third-party components

This local evaluation archive includes the application source, the installed backend/vendor Composer dependency tree (including its package license/notice files and composer/installed.json), backend/pdf-renderer/node_modules (including package notices), and the compiled React frontend. Their respective licenses govern use and redistribution; no licenses are replaced by this document.

Frontend dependency versions/licenses are listed in frontend/package-lock.json and LOCAL-COMPONENTS.json. React is MIT; Vite and build dependencies are used to build, with their existing source lock retained. Package maintainers must review all transitive notices for onward distribution. The packager extracts available frontend dependency LICENSE/NOTICE/COPYING files into local/third-party-notices/ so compiled frontend notices travel with the archive.

PHP, Node, Python, Microsoft Edge and Python wheels are not bundled here. Python requirements include PyMuPDF, whose licensing must be reviewed for the intended use (including its AGPL/commercial options). This package is an internal evaluation handoff, not a claim of commercial license clearance or a fully offline runtime installer. No Tesseract, Docker or WSL installation is included.
