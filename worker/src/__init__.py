"""
Worker source package for the Chatbot Knowledge Preparation System.

This package contains the Python Worker that processes pipeline steps
dispatched via SQS. Each step (preprocess, embedding, clustering,
cluster_analysis, knowledge_unit_generation) runs as an independent
unit of work, chained together automatically via step_chain.
"""
