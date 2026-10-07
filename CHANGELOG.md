# Changelog

All notable changes to this extension are documented here. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.22] - 2026-10-07

### Fixed
- Hyva quick view modal: the component script is now output before its markup, so the modal no longer throws "show is not defined" and related errors when Alpine starts before the inline script has run.
- Luma product listing: the quick view icon button is a full 44px by 44px tap target on every device (the Luma listing styles had narrowed it to 35px).
