<?php

function upload_to_cloudinary($filePath)
{
    $cloud_name = $_ENV['CLOUDINARY_CLOUD_NAME'];
    $api_key    = $_ENV['CLOUDINARY_API_KEY'];
    $api_secret = $_ENV['CLOUDINARY_API_SECRET'];

    $timestamp = time();
    $signature = sha1("timestamp={$timestamp}{$api_secret}");

    $url = "https://api.cloudinary.com/v1_1/{$cloud_name}/image/upload";

    $post = [
        "file" => new CURLFile($filePath),
        "api_key" => $api_key,
        "timestamp" => $timestamp,
        "signature" => $signature
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $result = curl_exec($ch);
    curl_close($ch);

    $response = json_decode($result, true);

    return $response['secure_url'] ?? null;
}
