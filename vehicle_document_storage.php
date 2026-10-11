<?php

function smDocumentDirectory(): string
{
    $directory = getenv('SMARTMOVE_DOCUMENT_DIR');

    return $directory ?: 'C:/xampp/SmartMoveDB_private/documents';
}