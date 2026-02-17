<?php

function uploadImageToCloudinary($tmpFilePath) {

    $cloudName = getenv("CLOUDINARY_CLOUD_NAME");
    $apiKey = getenv("CLOUDINARY_API_KEY");
    $apiSecret = getenv("CLOUDINARY_API_SECRET");

    if (!$cloudName || !$apiKey || !$apiSecret) {
        return null;
    }

    $timestamp = time();

    $signatureString = "timestamp={$timestamp}{$apiSecret}";
    $signature = sha1($signatureString);

    $url = "https://api.cloudinary.com/v1_1/{$cloudName}/image/upload";

    $postData = [
        "file" => new CURLFile($tmpFilePath),
        "api_key" => $apiKey,
        "timestamp" => $timestamp,
        "signature" => $signature
    ];

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);

    curl_close($ch);

    $result = json_decode($response, true);

    return $result["secure_url"] ?? null;
}
