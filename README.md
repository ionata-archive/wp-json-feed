# WordPress JSON Feed Helper

## Description

It helps to easily setup an endpoint for AJAX callbacks (eg. `^path/to/endpoint/feed.json`) that will produce a JSON response (cached for an hour by default).

It automatically invalidates the cache when a post is edited or trashed (can handle custom post types as well).

The generated JSON response is in your control, since it doesn't generate it automatically - it is up to you to produce an array or an object. It only encodes the result into a valid JSON string.

It generates a proper ETag and Last-Modified headers.

## Setup

See `setup.sample.php` for usage.
