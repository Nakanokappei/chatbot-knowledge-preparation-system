# ============================================================
# VPC Module
# ============================================================
# Creates a production-ready VPC with public and private subnet
# tiers spread across two availability zones. Public subnets
# host the ALB and NAT Gateway; private subnets host ECS tasks
# and RDS instances that must not be directly reachable from
# the internet.
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
# They reach the internet only via the NAT Gateway in the
# first public subnet.
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
# Elastic IP for NAT Gateway
# ----------------------------------------------------------
# A dedicated EIP ensures the NAT Gateway retains a stable
# outbound IP, which simplifies allowlisting on third-party
# services.
# ----------------------------------------------------------

resource "aws_eip" "nat" {
  domain = "vpc"

  tags = merge(var.common_tags, {
    Name = "${var.name_prefix}-nat-eip"
  })
}

# ----------------------------------------------------------
# NAT Gateway
# ----------------------------------------------------------
# A single NAT Gateway in the first public subnet provides
# outbound internet access for all private subnets. For
# production, consider adding a second NAT in the other AZ.
# ----------------------------------------------------------

resource "aws_nat_gateway" "this" {
  allocation_id = aws_eip.nat.id
  subnet_id     = aws_subnet.public[0].id

  tags = merge(var.common_tags, {
    Name = "${var.name_prefix}-nat"
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
# Private Route Table
# ----------------------------------------------------------
# A single route table shared by both private subnets sends
# all non-local traffic through the NAT Gateway so that ECS
# tasks can pull images and call external APIs.
# ----------------------------------------------------------

resource "aws_route_table" "private" {
  vpc_id = aws_vpc.this.id

  route {
    cidr_block     = "0.0.0.0/0"
    nat_gateway_id = aws_nat_gateway.this.id
  }

  tags = merge(var.common_tags, {
    Name = "${var.name_prefix}-private-rt"
  })
}

resource "aws_route_table_association" "private" {
  count = 2

  subnet_id      = aws_subnet.private[count.index].id
  route_table_id = aws_route_table.private.id
}
