# Directus Collector Plugin

The **Directus Collector** Plugin is an extension for [Grav CMS](http://github.com/getgrav/grav). Collects and generates pages from directus headless backend

## Installation

Installing the Directus Collector plugin can be done in one of three ways: The GPM (Grav Package Manager) installation method lets you quickly install the plugin with a simple terminal command, the manual method lets you do so via a zip file, and the admin method lets you do so via the Admin Plugin.

### GPM Installation (Preferred)

To install the plugin via the [GPM](http://learn.getgrav.org/advanced/grav-gpm), through your system's terminal (also called the command line), navigate to the root of your Grav-installation, and enter:

    bin/gpm install directus-collector

This will install the Directus Collector plugin into your `/user/plugins`-directory within Grav. Its files can be found under `/your/site/grav/user/plugins/directus-collector`.

### Manual Installation

To install the plugin manually, download the zip-version of this repository and unzip it under `/your/site/grav/user/plugins`. Then rename the folder to `directus-collector`. You can find these files on [GitHub](https://github.com/zebra-group/grav-plugin-directus-collector) or via [GetGrav.org](http://getgrav.org/downloads/plugins#extras).

You should now have all the plugin files under

    /your/site/grav/user/plugins/directus-collector
	
> NOTE: This plugin is a modular component for Grav which may require other plugins to operate, please see its [blueprints.yaml-file on GitHub](https://github.com/zebra-group/grav-plugin-directus-collector/blob/master/directus-collector.yaml).

### Admin Plugin

If you use the Admin Plugin, you can install the plugin directly by browsing the `Plugins`-menu and clicking on the `Add` button.

## Configuration

Before configuring this plugin, you should copy the `user/plugins/directus-collector/directus-collector.yaml` to `user/config/plugins/directus-collector.yaml` and only edit that copy.

Here is the default configuration and an explanation of available options:
```yaml
enabled: true
redirect_route: /404
mapping:
  table_name:
    path: 'user/pages/01.parent_page'
    filename: 'child_page'
    depth: 4
    frontmatter:
      column_title: title_fieldname
      column_slug: slug_fieldname
      column_date: date_fieldname
      column_sort: sort
      column_category: category_fieldname
  table2_name:
    ...
```
enabled: true - enables or disables the plugin. Default: true
redirect_route - this is the route for disabled (draft) collection items
table_name - replace it with the collection name for what you want to configure the child page generator
path: - the path from the parent folder. Under this path the collector generates subfolder with frontmatter maarkdown files.
filename: 'child_page' - will be used for name generation of the .md files. by default it generates child_page.md. if your site is multilingual, it will generate the translations too.
depth: - the depth for the directus request. This says, which depth will be generated for the directus plugin frontmatter
frontmatter: - here begins the frontmatter generator definition for the directus plugin
column_title: - the field name of the title. Will be used for frontmatter title attribute and slug generation, if no slug field is defined
column_slug: - the slug field. if you dont have a slug field, set it to false
column_date: - the date field for the generated page
column_sort: the sort field of your collection. if you have no sorting, set it to false
column_category: - the field for your category. if you dont use taxonomy, set it to false

Note that if you use the Admin Plugin, a file with your configuration named directus-collector.yaml will be saved in the `user/config/plugins/`-folder once the configuration is saved in the Admin.

## Usage

To start the generator and fetch your configured collections, call your.url/your_directus_hook_prefix/update

For automatic refreshing, you can configure a webhook in your directus instance.

For automatic Slug generation, the slug field must be writable in your api access.

