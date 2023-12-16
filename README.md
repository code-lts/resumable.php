# PHP backend for resumable.js

To use this project we recommend you to use [point cloud technology's fork](https://github.com/pointcloudtechnology/resumable.js) that is a maintained version of the original [resumable.js library](https://github.com/23/resumable.js/tree/master).


## Installation

To install, use composer:

```sh
composer require code-lts/resumable.php
```

## How to use

**upload.php**

```php
<?php
include __DIR__ . '/vendor/autoload.php';

use ResumableJs\Resumable;

// Any library that implements Psr\Http\Message\{ServerRequestInterface, ResponseInterface};
// See https://github.com/Nyholm/psr7 as a tested example

$resumable = new Resumable($request, $response);
$resumable->tempFolder = 'tmps';
$resumable->uploadFolder = 'uploads';
$resumable->process();

```

## More

### Setting custom filename(s)

```php
$originalName = $resumable->getOriginalFilename(); // will give you the original end-user file-name

$mySafeName = Security::sanitizeFileName($request->query('resumableFilename'));
$resumable->setFilename($mySafeName);// Override the safe filename

// process upload as normal
$resumable->process();

// you can also get file information after the upload is complete
if (true === $resumable->isUploadComplete()) { // true when the final file has been uploaded and chunks reunited.
    $filename = $resumable->getFilename();
}
```

## Removed features

- `$resumable->getOriginalFilename()` does not have a parameter to return the name without the extension
- `$resumable->getExtension()` implement the logic yourself
- `preProcess()` no longer exists, it was not very useful
- the default value of `uploadFolder` was `test/files/uploads` and is now `uploads`
- Does not calculate the number of chunks, it uses the param `resumableTotalChunks`

## Testing

```sh
$ ./vendor/bin/phpunit
```
