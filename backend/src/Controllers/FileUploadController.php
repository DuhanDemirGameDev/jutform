<?php

namespace JutForm\Controllers;

use JutForm\Core\Database;
use JutForm\Core\Request;
use JutForm\Core\RequestContext;
use JutForm\Core\Response;
use JutForm\Models\Form;
use JutForm\Models\KeyValueStore;

class FileUploadController
{
    private static function uploadDir(?int $formId = null): string
    {
        $root = dirname(__DIR__, 2);
        $dir = $root . '/storage/uploads';
        if ($formId !== null) {
            $dir .= '/form_' . $formId;
        }
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public static function buildStoredPath(int $formId, string $originalName): string
    {
        $baseName = pathinfo($originalName, PATHINFO_FILENAME);
        $baseName = is_string($baseName) ? strtolower($baseName) : 'upload';
        $safeBaseName = preg_replace('/[^a-z0-9]+/', '_', $baseName);
        $safeBaseName = trim((string) $safeBaseName, '_');
        if ($safeBaseName === '') {
            $safeBaseName = 'upload';
        }

        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $extension = is_string($extension) ? strtolower($extension) : '';
        $safeExtension = preg_replace('/[^a-z0-9]+/', '', $extension ?? '');
        $suffix = $safeExtension !== '' ? '.' . $safeExtension : '';

        return self::uploadDir($formId) . '/' . bin2hex(random_bytes(12)) . '_' . $safeBaseName . $suffix;
    }

    public function upload(Request $request, string $id): void
    {
        $uid = RequestContext::$currentUserId;
        if ($uid === null) {
            Response::error('Unauthorized', 401);
        }
        $form = Form::find((int) $id);
        if (!$form || (int) $form['user_id'] !== $uid) {
            Response::error('Not found', 404);
        }
        if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            Response::error('file required', 400);
        }
        $orig = $_FILES['file']['name'];
        $mime = $_FILES['file']['type'] ?? 'application/octet-stream';
        $size = (int) ($_FILES['file']['size'] ?? 0);
        $target = self::buildStoredPath((int) $id, $orig);
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $target)) {
            Response::error('Upload failed', 500);
        }
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare(
            'INSERT INTO file_uploads (form_id, submission_id, original_name, stored_path, mime_type, file_size, uploaded_at)
             VALUES (?, NULL, ?, ?, ?, ?, ?)'
        );
        $now = date('Y-m-d H:i:s');
        $stmt->execute([(int) $id, $orig, $target, $mime, $size, $now]);
        $fileId = (int) $pdo->lastInsertId();
        KeyValueStore::set((int) $id, 'logo_file_id', (string) $fileId);
        Response::json(['id' => $fileId, 'stored_path' => $target], 201);
    }

    public function download(Request $request, string $id): void
    {
        $uid = RequestContext::$currentUserId;
        if ($uid === null) {
            Response::error('Unauthorized', 401);
        }
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('SELECT * FROM file_uploads WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            Response::error('Not found', 404);
        }
        $form = Form::find((int) $row['form_id']);
        if (!$form || (int) $form['user_id'] !== $uid) {
            Response::error('Not found', 404);
        }
        $path = $row['stored_path'];
        $mime = $row['mime_type'] ?: 'application/octet-stream';
        Response::fileStream($path, $row['original_name'], $mime);
    }

    public function logo(Request $request, string $id): void
    {
        $fileIdRaw = KeyValueStore::get((int) $id, 'logo_file_id');
        if ($fileIdRaw === null || !ctype_digit($fileIdRaw)) {
            Response::error('Not found', 404);
        }
        $stmt = Database::getInstance()->prepare('SELECT * FROM file_uploads WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $fileIdRaw]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || (int) $row['form_id'] !== (int) $id) {
            Response::error('Not found', 404);
        }
        $path = $row['stored_path'];
        $mime = $row['mime_type'] ?: 'application/octet-stream';
        Response::inlineFile($path, $mime);
    }
}
