# ============================================================
# VPC Module
# ============================================================
# Creates a production-ready VPC with public and private subnet
# tiers spread across two availability zones. Public subnets
# host the ALB and NAT Gateway(s); private subnets host ECS
# tasks and RDS instances that must not be directly reachable
# from the internet.
#
# For production, set nat_gateway_count = 2 to deploy a NAT
# Gateway per AZ for high availability.
# ============================================================

# ----------------------------------------------------------
# VPC
# ----------------------------------------------------------
# Enable DNS hostnames so that resources receive public DNS
# entries, and DNS support so that Route 53 private hosted
# zones resolve correctly inside the VPC.
# ----------------------------------------------------------

resource "aws_vpc" "this" {
  cidr_block           = var.vpc_cidr
  enable_dns_hostnames = true
  enable_dns_support   = true

  tags = merge(var.common_tags, {
    Name = "${var.name_prefix}-vpc"
  })
}

# ----------------------------------------------------------
# Public Subnets
# ----------------------------------------------------------
# Two public subnets in separate AZs provide redundancy for
# the ALB. Instances launched here receive public IPs so they
# can route through the Internet Gateway.
# ----------------------------------------------------------

resource "aws_subnet" "public" {
  count = 2

  vpc_id                  = aws_vpc.this.id
  cidr_block              = cidrsubnet(var.vpc_cidr, 8, count.index + 1) # 10.0.1.0/24, 10.0.2.0/24
  availability_zone       = var.availability_zones[count.index]
  map_public_ip_on_launch = true

  tags = merge(var.common_tags, {
    Name = "${var.name_prefix}-public-${var.availability_zones[count.index]}"
    Tier = "public"
  })
}

# ----------------------------------------------------------
# Private Subnets
# ----------------------------------------------------------
# Two private subnets host ECS tasks and the RDS instance.
# They reach the internet via NAT Gateway(s) in the public
# subnets.
# ----------------------------------------------------------

resource "aws_subnet" "private" {
  count = 2

  vpc_id            = aws_vpc.this.id
  cidr_block        = cidrsubnet(var.vpc_cidr, 8, count.index + 11) # 10.0.11.0/24, 10.0.12.0/24
  availability_zone = var.availability_zones[count.index]

  tags = merge(var.common_tags, {
    Name = "${var.name_prefix}-private-${var.availability_zones[count.index]}"
    Tier = "private"
  })
}

# ----------------------------------------------------------
# Internet Gateway
# ----------------------------------------------------------
# Provides the public subnets with a path to the internet.
# ----------------------------------------------------------

resource "aws_internet_gateway" "this" {
  vpc_id = aws_vpc.this.id

  tags = merge(var.common_tags, {
    Name = "${var.name_prefix}-igw"
  })
}

# ----------------------------------------------------------
# Elastic IP(s) for NAT Gateway(s)
# ----------------------------------------------------------
# One EIP per NAT Gateway. For dev (count=1) a single NAT
# is used; for prod (count=2) each AZ gets its own NAT with
# a stable outbound IP.
# ----------------------------------------------------------

resource "aws_eip" "nat" {
  count  = var.nat_gateway_count
  domain = "vpc"

  tags = merge(var.common_tags, {
    Name = "${var.name_prefix}-nat-eip-${count.index}"
  })
}

# ----------------------------------------------------------
# NAT Gateway(s)
# ----------------------------------------------------------
# Dev: a single NAT Gateway in the first public subnet.
# Prod: one NAT Gateway per AZ for high availability — if
# one AZ goes down, the other's private subnet retains
# internet access.
# ----------------------------------------------------------

resource "aws_nat_gateway" "this" {
  count = var.nat_gateway_count

  allocation_id = aws_eip.nat[count.index].id
  subnet_id     = aws_subnet.public[count.index].id

  tags = merge(var.common_tags, {
    Name = "${var.name_prefix}-nat-${count.index}"
  })

  # The NAT Gateway depends on the IGW being attached first.
  depends_on = [aws_internet_gateway.this]
}

# ----------------------------------------------------------
# Public Route Table
# ----------------------------------------------------------
# A single route table shared by both public subnets sends
# all non-local traffic to the Internet Gateway.
# ----------------------------------------------------------

resource "aws_route_table" "public" {
  vpc_id = aws_vpc.this.id

  route {
    cidr_block = "0.0.0.0/0"
    gateway_id = aws_internet_gateway.this.id
  }

  tags = merge(var.common_tags, {
    Name = "${var.name_prefix}-public-rt"
  })
}

resource "aws_route_table_association" "public" {
  count = 2

  subnet_id      = aws_subnet.public[count.index].id
  route_table_id = aws_route_table.public.id
}

# ----------------------------------------------------------
# Private Route Table(s)
# ----------------------------------------------------------
# When nat_gateway_count == 1, a single route table is shared
# by both private subnets (same as before). When count == 2,
# each private subnet gets its own route table pointing to
# the NAT Gateway in its AZ.
# ----------------------------------------------------------

resource "aws_route_table" "private" {
  count  = var.nat_gateway_count
  vpc_id = aws_vpc.this.id

  route {
    cidr_block     = "0.0.0.0/0"
    nat_gateway_id = aws_nat_gateway.this[count.index].id
  }

  tags = merge(var.common_tags, {
    Name = "${var.name_prefix}-private-rt-${count.index}"
  })
}

resource "aws_route_table_association" "private" {
  count = 2

  subnet_id      = aws_subnet.private[count.index].id
  # When only 1 NAT, both subnets share route table [0].
  # When 2 NATs, each subnet uses its own route table.
  route_table_id = aws_route_table.private[min(count.index, var.nat_gateway_count - 1)].id
}
