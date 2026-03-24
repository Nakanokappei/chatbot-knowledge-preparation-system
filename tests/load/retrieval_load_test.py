#!/usr/bin/env python3
"""
Load test for CKPS Retrieval and Chat APIs.

CTO-defined SLO targets:
- Retrieval p95 < 800ms, p99 < 2s
- Chat p95 < 5s, p99 < 12s
- Error rate < 1%
- Throughput: 50 retrieve/s, 20 concurrent chat

Usage:
    pip install aiohttp
    python3 retrieval_load_test.py --base-url http://localhost:8000 --token YOUR_TOKEN --dataset-id 1
"""

import argparse
import asyncio
import json
import statistics
import time

import aiohttp


# CTO-defined SLO targets
SLO = {
    "retrieve_p95": 800,    # ms
    "retrieve_p99": 2000,   # ms
    "chat_p95": 5000,       # ms
    "chat_p99": 12000,      # ms
    "error_rate": 1.0,      # percent
}

SAMPLE_QUERIES = [
    "How do I reset my password?",
    "I want to cancel my subscription",
    "My order hasn't arrived yet",
    "How do I get a refund?",
    "Can I change my email address?",
    "The app keeps crashing",
    "I was charged twice",
    "How do I export my data?",
    "I need to speak to a manager",
    "What are your business hours?",
]


async def make_request(session, url, payload, headers):
    """Make a single API request and return latency + status."""
    start = time.time()
    try:
        async with session.post(url, json=payload, headers=headers) as resp:
            await resp.text()
            latency_ms = (time.time() - start) * 1000
            return {"latency_ms": latency_ms, "status": resp.status, "error": None}
    except Exception as e:
        latency_ms = (time.time() - start) * 1000
        return {"latency_ms": latency_ms, "status": 0, "error": str(e)}


async def run_retrieval_test(base_url, token, dataset_id, concurrency, duration_seconds):
    """Run retrieval load test."""
    url = f"{base_url}/api/retrieve"
    headers = {"Authorization": f"Bearer {token}", "Content-Type": "application/json"}
    results = []
    end_time = time.time() + duration_seconds

    async with aiohttp.ClientSession() as session:
        while time.time() < end_time:
            tasks = []
            for i in range(concurrency):
                query = SAMPLE_QUERIES[i % len(SAMPLE_QUERIES)]
                payload = {"query": query, "dataset_id": dataset_id, "top_k": 5}
                tasks.append(make_request(session, url, payload, headers))

            batch_results = await asyncio.gather(*tasks)
            results.extend(batch_results)

    return results


async def run_chat_test(base_url, token, dataset_id, concurrency, duration_seconds):
    """Run chat load test."""
    url = f"{base_url}/api/chat"
    headers = {"Authorization": f"Bearer {token}", "Content-Type": "application/json"}
    results = []
    end_time = time.time() + duration_seconds

    async with aiohttp.ClientSession() as session:
        while time.time() < end_time:
            tasks = []
            for i in range(concurrency):
                query = SAMPLE_QUERIES[i % len(SAMPLE_QUERIES)]
                payload = {"message": query, "dataset_id": dataset_id}
                tasks.append(make_request(session, url, payload, headers))

            batch_results = await asyncio.gather(*tasks)
            results.extend(batch_results)

    return results


def analyze_results(results, api_name):
    """Analyze load test results and check against SLO."""
    latencies = [r["latency_ms"] for r in results if r["status"] == 200]
    errors = [r for r in results if r["status"] != 200]
    error_rate = (len(errors) / len(results)) * 100 if results else 0

    if not latencies:
        print(f"\n{api_name}: No successful requests!")
        return False

    latencies.sort()
    p50 = latencies[int(len(latencies) * 0.5)]
    p95 = latencies[int(len(latencies) * 0.95)]
    p99 = latencies[int(len(latencies) * 0.99)]

    p95_target = SLO.get(f"{api_name}_p95", 999999)
    p99_target = SLO.get(f"{api_name}_p99", 999999)

    p95_pass = p95 <= p95_target
    p99_pass = p99 <= p99_target
    error_pass = error_rate <= SLO["error_rate"]

    print(f"\n{'='*50}")
    print(f"{api_name.upper()} RESULTS")
    print(f"{'='*50}")
    print(f"Total requests: {len(results)}")
    print(f"Successful:     {len(latencies)}")
    print(f"Errors:         {len(errors)} ({error_rate:.1f}%) {'PASS' if error_pass else 'FAIL'}")
    print(f"p50:            {p50:.0f}ms")
    print(f"p95:            {p95:.0f}ms (target: {p95_target}ms) {'PASS' if p95_pass else 'FAIL'}")
    print(f"p99:            {p99:.0f}ms (target: {p99_target}ms) {'PASS' if p99_pass else 'FAIL'}")
    print(f"Mean:           {statistics.mean(latencies):.0f}ms")
    print(f"Throughput:     {len(latencies) / (max(latencies) / 1000):.1f} req/s")

    return p95_pass and p99_pass and error_pass


async def main():
    parser = argparse.ArgumentParser(description="CKPS Load Test")
    parser.add_argument("--base-url", default="http://localhost:8000")
    parser.add_argument("--token", required=True, help="Sanctum API token")
    parser.add_argument("--dataset-id", type=int, required=True)
    parser.add_argument("--duration", type=int, default=120, help="Test duration in seconds")
    args = parser.parse_args()

    print(f"CKPS Load Test — {args.base_url}")
    print(f"Dataset: {args.dataset_id}, Duration: {args.duration}s\n")

    # Retrieval test: 50 concurrent
    print("Running Retrieval test (50 concurrent)...")
    retrieve_results = await run_retrieval_test(
        args.base_url, args.token, args.dataset_id, 50, args.duration
    )
    retrieve_pass = analyze_results(retrieve_results, "retrieve")

    # Chat test: 20 concurrent
    print("\nRunning Chat test (20 concurrent)...")
    chat_results = await run_chat_test(
        args.base_url, args.token, args.dataset_id, 20, args.duration
    )
    chat_pass = analyze_results(chat_results, "chat")

    print(f"\n{'='*50}")
    print(f"OVERALL: {'ALL SLOs MET' if retrieve_pass and chat_pass else 'SLO VIOLATIONS DETECTED'}")
    print(f"{'='*50}")


if __name__ == "__main__":
    asyncio.run(main())
