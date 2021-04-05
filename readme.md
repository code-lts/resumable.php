# PHP backend for resumable.js


## Installation

To install, use composer:

```sh
composer require code-lts/resumable.php
```


## How to use
**upload.php**

```
<?php
include 'vendor/autoload.php';

use ResumableJs\Network\SimpleRequest;
use ResumableJs\Network\SimpleResponse;
use ResumableJs\Resumable;

$request = new SimpleRequest();
$response = new SimpleResponse();

$resumable = new Resumable($request, $response);
$resumable->tempFolder = 'tmps';
$resumable->uploadFolder = 'uploads';
$resumable->process();

```

## More ##
### Setting custom filename(s) ###

```
// custom filename (extension from original file will be magically removed and re-appended)
$originalName = $resumable->getOriginalFilename(Resumable::WITHOUT_EXTENSION); // will gove you "original Name" instead of "original Name.png"

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
```
$ ./vendor/bin/phpunit
```
