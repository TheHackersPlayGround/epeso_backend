-- Soft-delete support for the Documents Tab (folders + document_library),
-- so deleting a folder/file plugs into the same app-wide Recycle Bin every
-- other module already uses, instead of being a permanent delete.

ALTER TABLE folders
    ADD COLUMN deleted_at TIMESTAMP NULL,
    ADD COLUMN deleted_by INTEGER NULL REFERENCES users(user_id);

ALTER TABLE document_library
    ADD COLUMN deleted_at TIMESTAMP NULL,
    ADD COLUMN deleted_by INTEGER NULL REFERENCES users(user_id);
