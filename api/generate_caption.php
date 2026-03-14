<?php
session_start();
header('Content-Type: application/json');


$apiKey = "AIzaSyB4EL_Wji6fvqVLYQ40vi6QkpWfVA-OWP4"; // Replace with your actual API key
// =============================================

$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-lite-latest:generateContent?key=" . $apiKey;

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['error' => 'No data received']);
    exit;
}

$title       = $data['title']       ?? '';
$description = $data['description'] ?? '';
$category    = $data['category']    ?? 'General';
$platforms   = $data['platforms']   ?? 'Social Media';

$prompt = "Write a short, engaging social media caption for a university/college post. Keep it under 150 words. Be catchy, use 2-3 relevant emojis, and make it appropriate for a college audience.

Event/Post Title: $title
Description: $description
Category: $category
Target Platforms: $platforms

Reply with ONLY the caption text. No explanations, no labels, just the caption.";

$payload = [
    "contents" => [
        [
            "parts" => [
                ["text" => $prompt]
            ]
        ]
    ],
    "generationConfig" => [
        "temperature"     => 0.8,
        "maxOutputTokens" => 300
    ]
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL,            $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST,           true);
curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT,        30);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if (curl_errno($ch)) {
    echo json_encode(['error' => 'Connection error: ' . curl_error($ch)]);
} elseif ($httpCode !== 200) {
    $decoded = json_decode($response, true);
    $message = $decoded['error']['message'] ?? "API returned status $httpCode";
    echo json_encode(['error' => $message]);
} else {
    echo $response;
}

curl_close($ch);