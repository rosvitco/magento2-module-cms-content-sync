# Rosvit_CmsContentSync

Export and import Magento 2 CMS Pages and Blocks between environments as JSON.

Move content from staging to production (or between any two installs) without copying
databases: select pages or blocks in the admin grid, download a JSON file, upload it in
the other environment, review a preview and import.

## Features

- **Mass export** from the CMS Pages and CMS Blocks grids into a single JSON file.
- **Preview before import**: every row shows whether it will be created, updated, left
  unchanged or fails, and you choose which rows to import.
- **Portable store scopes**: store views travel by code, not by id.
- **Portable block references**: blocks used inside pages or other blocks
  (`{{widget type="Magento\Cms\Block\Widget\Block" ...}}`, `{{block id="..."}}`, Page Builder)
  are exported by identifier and mapped back to the local block id on import. Missing blocks
  are reported in the preview and after the import.
- Blocks are imported before pages, so one file can carry a page and the blocks it uses.

## Requirements

- Magento Open Source / Adobe Commerce 2.4.x
- PHP 8.2 – 8.5

## Installation

```bash
composer require rosvit/module-cms-content-sync
bin/magento module:enable Rosvit_CmsContentSync
bin/magento setup:upgrade
bin/magento cache:flush
```

## Usage

1. **Export**: go to *Content → Pages* (or *Blocks*), select the rows, and choose
   *Actions → Export to JSON*.
2. **Import**: in the target environment go to *Content → CMS Content Sync*, upload the
   file, review the preview and click *Import Selected*.

Access is controlled by two ACL resources, *Export CMS Content* and *Import CMS Content*,
under *Content → CMS Content Sync*.

## License

[MIT](LICENSE)
