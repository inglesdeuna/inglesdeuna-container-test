<?php

use Cloudinary\Configuration\Configuration;
use Cloudinary\Api\Upload\UploadApi;

require_once __DIR__ . '/../vendor/autoload.php';

Configuration::instance([
    'cloud' => [
        'cloud_name' => $_ENV['CLOUDINARY_CLOUD_NAME'],
        'api_key'    => $_ENV['CLOUDINARY_API_KEY'],
        'api_secret' => $_ENV['CLOUDINARY_API_SECRET']
    ],
    'url' => [
        'secure' => true
    ]
]);

function upload_to_cloudinary($filePath)
{
    $upload = new UploadApi();
    $result = $upload->upload($filePath);

    return $result['secure_url'];
}
