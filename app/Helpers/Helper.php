<?php

namespace App\Helpers;

class Helper
{
    public static function upload(string $file, string $path, string $newName = null): array
    {
        $filename = pathinfo($file['name'], PATHINFO_FILENAME);
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fileName = $newName ?? (uniqid() . '.' . $extension);
        $targetPath = rtrim($path, '/') . '/' . $fileName;

        if (!file_exists($path)) {
            mkdir($path, 0777, true);
        }

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            return [
                'success' => true,
                'path' => $targetPath,
                'filename' => $fileName,
                'original_name' => $file['name'],
                'size' => $file['size'],
                'mime_type' => $file['type'],
            ];
        }

        return ['success' => false, 'message' => 'Falha ao fazer upload'];
    }

    public static function formatDate(string $date, string $format = 'd/m/Y'): string
    {
        $timestamp = strtotime($date);
        return date($format, $timestamp);
    }

    public static function formatTimeAgo(string $date): string
    {
        $timestamp = strtotime($date);
        $now = time();
        $diff = $now - $timestamp;

        if ($diff < 60) return 'há pouco tempo';
        if ($diff < 3600) return floor($diff / 60) . ' min atrás';
        if ($diff < 86400) return floor($diff / 3600) . ' h atrás';
        if ($diff < 2592000) return floor($diff / 86400) . ' dias atrás';
        return floor($diff / 2592000) . ' meses atrás';
    }

    public static function generateSlug(string $text): string
    {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
        $text = preg_replace('/\s+/', '-', $text);
        $text = trim($text, '-');
        return $text;
    }

    public static function limitText(string $text, int $limit = 100): string
    {
        if (strlen($text) > $limit) {
            return substr($text, 0, $limit) . '...';
        }
        return $text;
    }

    public static function maskEmail(string $email): string
    {
        if (!preg_match('/^(.+)@(.+\\.\\w+)$/', $email, $matches)) {
            return $email;
        }

        $name = $matches[1];
        $domain = $matches[2];
        $name = strlen($name) > 3 ? substr($name, 0, 3) . '***' : $name;

        return $name . '@' . $domain;
    }

    public static function maskPhone(string $phone): string
    {
        $phone = preg_replace('/[0-9]/', '*', $phone);
        return substr($phone, 0, 3) . '-' . substr($phone, 4, 3) . '-' . substr($phone, 7);
    }

    public static function seoFriendlyUrl(string $text, string $separator = '-'): string
    {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9\s]/', '', $text);
        $text = preg_replace('/\\s+/', $separator, $text);
        $text = trim($text, $separator);
        return $text;
    }
}
