-- Docker PostgreSQL initialization script
-- Creates pgvector extension required by knowledge_units.embedding column

CREATE EXTENSION IF NOT EXISTS vector;
