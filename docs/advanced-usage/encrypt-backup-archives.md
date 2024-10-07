---
title: Encrypt backup archives
weight: 4
---

It's common to encrypt backups before storing them somewhere to prevent unauthorized access.
To do so you can configure this package to use client-side symmetric zip file password encryption before storing the archive somewhere.

By default you only have to define the `BACKUP_ARCHIVE_PASSWORD` environment variable in your `.env` file.

If you want to customize this you can configure the `backup.backup.password` and `backup.backup.encryption` keys in your `config/backup.php` file.

It's important to try this workflow and also to decrypt a backup archive.
So you know that it works and you have a working backup restore solution.

**Warning:** the default MacOS app to (un)archive ZIPs seems unable to open/extract encrypted ZIP files.
You should use an app like [The Unarchiver](https://theunarchiver.com/) or [BetterZip](https://macitbetter.com/).
