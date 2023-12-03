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

## More ##
### Setting custom filename(s) ###

```php
// custom filename (extension from original file will be magically removed and re-appended)
$originalName = $resumable->getOriginalFilename(Resumable::WITHOUT_EXTENSION); // will give you "original Name" instead of "original Name.png"

// do some slugification or whatever you need...
$slugifiedname = my_slugify($originalName); // this is up to you, it as ported out of the library.
$resumable->setFilename($slugifiedname);

// process upload as normal
$resumable->process();

// you can also get file information after the upload is complete
if (true === $resumable->isUploadComplete()) { // true when the final file has been uploaded and chunks reunited.
    $extension = $resumable->getExtension();
    $filename = $resumable->getFilename();
}
```

## Testing

```sh
$ ./vendor/bin/phpunit
```
