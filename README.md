# siteservice/clp-utils

A set of utility functions for managing WordPress sites on [CloudPanel.io](https://www.cloudpanel.io/).

---

## ⚠️ WARNING

**This tool is intended to be run as the root user. Attempting to use it without root privileges will lead to permission issues and potential errors.**

---

## Quick Links

- [Using](#using)
- [Installing](#installing)

---

## Using

This package implements the following commands:

### `wp clp-utils copy <options> --allow-root`

Copies a WordPress site between a source and a destination environment.

```
wp clp-utils copy
```

**OPTIONS**

    [--src=<user>]
    User that owns the source environment.

    [--dest=<user>]
    User that owns the destination environment.

    [--files=<permissions>]
    File permissions to set on the destination environment.

    [--folders=<permissions>]
    Folder permissions to set on the destination environment.

    [--plugins=<boolean>]
    Whether to copy plugins from the source environment to the destination environment.

    [--themes=<boolean>]
    Whether to copy themes from the source environment to the destination environment.

    [--uploads=<boolean>]
    Whether to copy uploads from the source environment to the destination environment.

**EXAMPLES**

`wp clp-utils copy --src=production_user --dest=staging_user --files=660 --folders=770 --allow-root`

## Installing

Clone this repo to your server:

    git clone https://github.com/siteservice/clp-utils.git
