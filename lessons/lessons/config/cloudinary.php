<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Cloudinary\Configuration\Configuration;
use Cloudinary\Api\Upload\UploadApi;

/* =============================
   CONFIGURAR CLOUDINARY
============================= */

Configuration::instance([
    'cloud' => [
        'cloud_name' => getenv('CLOUDINARY_CLOUD_NAME'),
        'api_key'    => getenv('CLOUDINARY_API_KEY'),
        'api_secret' => getenv('CLOUDINARY_API_SECRET'),
    ],
    'url' => [
        'secure' => true
    ]
]);

/* =============================
   FUNCION SUBIR IMAGEN
============================= */

function uploadToCloudinary($fileTmpPath)
{
    $upload = new UploadApi();

    $result = $upload->upload($fileTmpPath);

    return $result['secure_url'];
}
