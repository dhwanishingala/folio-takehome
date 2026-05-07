<?php
return [
    'version' => '003',
    'up' => [
        // FTS5 virtual table with trigram tokenizer — enables indexed substring search.
        // External content table: the FTS index points back to documents for retrieval.
        "CREATE VIRTUAL TABLE documents_fts USING fts5(
            title,
            content='documents',
            content_rowid='id',
            tokenize='trigram'
        )",

        // Populate from existing rows
        "INSERT INTO documents_fts(rowid, title) SELECT id, title FROM documents",

        // Keep index in sync
        "CREATE TRIGGER docs_fts_insert AFTER INSERT ON documents BEGIN
            INSERT INTO documents_fts(rowid, title) VALUES (new.id, new.title);
        END",

        "CREATE TRIGGER docs_fts_update AFTER UPDATE OF title ON documents BEGIN
            INSERT INTO documents_fts(documents_fts, rowid, title) VALUES('delete', old.id, old.title);
            INSERT INTO documents_fts(rowid, title) VALUES (new.id, new.title);
        END",

        "CREATE TRIGGER docs_fts_delete AFTER DELETE ON documents BEGIN
            INSERT INTO documents_fts(documents_fts, rowid, title) VALUES('delete', old.id, old.title);
        END",
    ],
];
