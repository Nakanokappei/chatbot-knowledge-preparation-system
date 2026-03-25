"""
Pipeline step modules for the Chatbot Knowledge Preparation System.

Each module in this package implements a single pipeline step with an
execute() entry point. Steps are registered in main.py's STEP_HANDLERS
and chained via step_chain.dispatch_next_step().

Step execution order:
  ping              - Integration test (Phase 0 only)
  preprocess        - CSV parsing and text normalization
  embedding         - Vector generation via Bedrock Titan Embed v2
  clustering        - Group embeddings (HDBSCAN, K-Means, Agglomerative, Leiden)
  cluster_analysis  - LLM-powered topic naming and summarization
  knowledge_unit_generation - Convert clusters into Knowledge Units
"""
