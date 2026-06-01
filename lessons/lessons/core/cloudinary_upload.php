<?php

function cloudinary_env(string $key): string
{
    $val = $_ENV[$key] ?? getenv($key) ?? '';
    return is_string($val) ? trim($val) : '';
}

function upload_to_cloudinary($filePath)
{
    $cloud_name = cloudinary_env('CLOUDINARY_CLOUD_NAME');
    $api_key    = cloudinary_env('CLOUDINARY_API_KEY');
    $api_secret = cloudinary_env('CLOUDINARY_API_SECRET');

    if ($cloud_name === '' || $api_key === '' || $api_secret === '') {
        return null;
    }

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

function upload_video_to_cloudinary(string $filePath): ?string
{
    $cloud_name = cloudinary_env('CLOUDINARY_CLOUD_NAME');
    $api_key    = cloudinary_env('CLOUDINARY_API_KEY');
    $api_secret = cloudinary_env('CLOUDINARY_API_SECRET');

    if ($cloud_name === '' || $api_key === '' || $api_secret === '') {
        return null;
    }

    $timestamp = time();
    $signature = sha1("timestamp={$timestamp}{$api_secret}");

    $url = "https://api.cloudinary.com/v1_1/{$cloud_name}/video/upload";

    $post = [
        "file"      => new CURLFile($filePath),
        "api_key"   => $api_key,
        "timestamp" => $timestamp,
        "signature" => $signature,
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);

    $result = curl_exec($ch);
    curl_close($ch);

    $response = json_decode((string) $result, true);

    return isset($response['secure_url']) ? (string) $response['secure_url'] : null;
}

function upload_audio_to_cloudinary(string $filePath): ?string
{
    $cloud_name = cloudinary_env('CLOUDINARY_CLOUD_NAME');
    $api_key    = cloudinary_env('CLOUDINARY_API_KEY');
    $api_secret = cloudinary_env('CLOUDINARY_API_SECRET');

    if ($cloud_name === '' || $api_key === '' || $api_secret === '') {
        return null;
    }

    $mime = (string) @mime_content_type($filePath);
    if ($mime === '') {
        $mime = 'audio/mpeg';
    }

    $extByMime = [
        'audio/mpeg' => '.mp3',
        'audio/mp3' => '.mp3',
        'audio/wav' => '.wav',
        'audio/x-wav' => '.wav',
        'audio/ogg' => '.ogg',
        'audio/aac' => '.aac',
        'audio/mp4' => '.m4a',
        'audio/x-m4a' => '.m4a',
        'audio/webm' => '.webm',
    ];
    $filename = 'audio_upload' . ($extByMime[$mime] ?? '.mp3');

    $timestamp = time();
    $signature = sha1("timestamp={$timestamp}{$api_secret}");

    $url = "https://api.cloudinary.com/v1_1/{$cloud_name}/raw/upload";

    $post = [
        'file' => new CURLFile($filePath, $mime, $filename),
        'api_key' => $api_key,
        'timestamp' => $timestamp,
        'signature' => $signature,
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);

    $result = curl_exec($ch);
    curl_close($ch);

    $response = json_decode((string) $result, true);

    return isset($response['secure_url']) ? (string) $response['secure_url'] : null;
}
