# CDN Module — CloudFront Distribution for Public Assets
#
# Creates a CloudFront distribution backed by S3 for serving
# public assets (bot icons, etc.) with edge caching.
# Uses Origin Access Control (OAC) so the S3 bucket stays private.

# Origin Access Control for secure S3 access
resource "aws_cloudfront_origin_access_control" "this" {
  name                              = "${var.name_prefix}-oac"
  origin_access_control_origin_type = "s3"
  signing_behavior                  = "always"
  signing_protocol                  = "sigv4"
}

resource "aws_cloudfront_distribution" "this" {
  enabled             = true
  comment             = "${var.name_prefix} public assets"
  default_root_object = ""
  price_class         = "PriceClass_200" # US, Europe, Asia — no South America/Africa

  origin {
    domain_name              = var.s3_bucket_regional_domain
    origin_id                = "s3-assets"
    origin_access_control_id = aws_cloudfront_origin_access_control.this.id
    origin_path              = "/assets" # Only expose the /assets prefix
  }

  default_cache_behavior {
    allowed_methods        = ["GET", "HEAD"]
    cached_methods         = ["GET", "HEAD"]
    target_origin_id       = "s3-assets"
    viewer_protocol_policy = "redirect-to-https"
    compress               = true

    # Cache for 1 year (immutable content-addressed uploads)
    min_ttl     = 0
    default_ttl = 86400    # 1 day
    max_ttl     = 31536000 # 1 year

    forwarded_values {
      query_string = false
      cookies {
        forward = "none"
      }
    }
  }

  restrictions {
    geo_restriction {
      restriction_type = "none"
    }
  }

  viewer_certificate {
    cloudfront_default_certificate = true
  }

  tags = var.common_tags
}

# S3 bucket policy: allow CloudFront OAC to read /assets/* objects
resource "aws_s3_bucket_policy" "cdn_read" {
  bucket = var.s3_bucket_id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid       = "AllowCloudFrontOAC"
        Effect    = "Allow"
        Principal = { Service = "cloudfront.amazonaws.com" }
        Action    = "s3:GetObject"
        Resource  = "${var.s3_bucket_arn}/assets/*"
        Condition = {
          StringEquals = {
            "AWS:SourceArn" = aws_cloudfront_distribution.this.arn
          }
        }
      }
    ]
  })
}
