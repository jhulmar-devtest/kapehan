<?php

function refundQrStorageDirectory(): string
{
  return dirname(__DIR__) . '/storage/private/refund-qr';
}

/** Store only real, small raster images under a random, non-public filename. */
function storeRefundQrUpload(?array $file): ?string
{
  if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
  if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    throw new RuntimeException('The QR image could not be uploaded. Please try again.');
  }
  if (!is_uploaded_file((string) ($file['tmp_name'] ?? '')) || (int) ($file['size'] ?? 0) > 2 * 1024 * 1024) {
    throw new RuntimeException('The QR image must be a valid image no larger than 2 MB.');
  }

  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = $finfo->file((string) $file['tmp_name']);
  $extensions = [
    'image/jpeg' => '.jpg',
    'image/png' => '.png',
    'image/webp' => '.webp',
  ];
  if (!isset($extensions[$mime]) || @getimagesize((string) $file['tmp_name']) === false) {
    throw new RuntimeException('Upload a QR image in JPG, PNG, or WebP format.');
  }

  $directory = refundQrStorageDirectory();
  if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('Private QR storage is unavailable. Please contact the administrator.');
  }

  $filename = bin2hex(random_bytes(24)) . $extensions[$mime];
  if (!move_uploaded_file((string) $file['tmp_name'], $directory . '/' . $filename)) {
    throw new RuntimeException('The QR image could not be saved. Please try again.');
  }
  @chmod($directory . '/' . $filename, 0600);
  return $filename;
}

function deleteRefundQrFile(?string $filename): void
{
  if (!$filename || !preg_match('/\A[a-f0-9]{48}\.(?:jpg|png|webp)\z/', $filename)) return;
  $path = refundQrStorageDirectory() . '/' . $filename;
  if (is_file($path)) @unlink($path);
}

function refundQrFilePath(?string $filename): ?string
{
  if (!$filename || !preg_match('/\A[a-f0-9]{48}\.(?:jpg|png|webp)\z/', $filename)) return null;
  $path = refundQrStorageDirectory() . '/' . $filename;
  return is_file($path) ? $path : null;
}

function refundQrMimeType(string $filename): string
{
  return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
    'jpg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    default => 'application/octet-stream',
  };
}
