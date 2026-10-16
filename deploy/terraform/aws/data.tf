resource "random_password" "db" {
  length  = 32
  special = false
}

resource "aws_db_subnet_group" "this" {
  name       = var.name
  subnet_ids = module.vpc.database_subnets
}

resource "aws_db_instance" "this" {
  identifier                   = var.name
  engine                       = "postgres"
  engine_version               = "16"
  instance_class               = var.db_instance_class
  allocated_storage            = 20
  max_allocated_storage        = 200
  db_name                      = "tintype"
  username                     = "tintype"
  password                     = random_password.db.result
  db_subnet_group_name         = aws_db_subnet_group.this.name
  vpc_security_group_ids       = [aws_security_group.data.id]
  storage_encrypted            = true
  backup_retention_period      = 7
  deletion_protection          = true
  skip_final_snapshot          = false
  final_snapshot_identifier    = "${var.name}-final"
  performance_insights_enabled = true
}

resource "aws_elasticache_subnet_group" "this" {
  name       = var.name
  subnet_ids = module.vpc.private_subnets
}

resource "aws_elasticache_cluster" "this" {
  cluster_id         = var.name
  engine             = "redis"
  node_type          = var.redis_node_type
  num_cache_nodes    = 1
  subnet_group_name  = aws_elasticache_subnet_group.this.name
  security_group_ids = [aws_security_group.data.id]
}

resource "aws_s3_bucket" "this" {
  bucket_prefix = "${var.name}-"
}

resource "aws_s3_bucket_public_access_block" "this" {
  bucket                  = aws_s3_bucket.this.id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_server_side_encryption_configuration" "this" {
  bucket = aws_s3_bucket.this.id
  rule {
    apply_server_side_encryption_by_default { sse_algorithm = "AES256" }
  }
}

resource "aws_s3_bucket_lifecycle_configuration" "this" {
  bucket = aws_s3_bucket.this.id
  # Raw CLI uploads are unpacked and deleted; anything left behind is junk.
  rule {
    id     = "expire-uploads"
    status = "Enabled"
    filter { prefix = "uploads/" }
    expiration { days = 2 }
  }
  rule {
    id     = "abort-multipart"
    status = "Enabled"
    filter {}
    abort_incomplete_multipart_upload { days_after_initiation = 2 }
  }
}

# CLI uploads go straight to S3 with presigned PUTs from CI runners.
resource "aws_s3_bucket_cors_configuration" "this" {
  bucket = aws_s3_bucket.this.id
  cors_rule {
    allowed_methods = ["PUT"]
    allowed_origins = ["*"]
    allowed_headers = ["*"]
    max_age_seconds = 3000
  }
}

resource "random_password" "app_key" {
  length  = 32
  special = false
}

resource "random_password" "worker_token" {
  length  = 48
  special = false
}

resource "random_password" "reverb_key" {
  length  = 24
  special = false
}

resource "random_password" "reverb_secret" {
  length  = 40
  special = false
}

resource "aws_secretsmanager_secret" "app" {
  name_prefix = "${var.name}-app-"
}

resource "aws_secretsmanager_secret_version" "app" {
  secret_id = aws_secretsmanager_secret.app.id
  secret_string = jsonencode({
    APP_KEY              = "base64:${base64encode(random_password.app_key.result)}"
    DB_PASSWORD          = random_password.db.result
    TINTYPE_WORKER_TOKEN = random_password.worker_token.result
    REVERB_APP_KEY       = random_password.reverb_key.result
    REVERB_APP_SECRET    = random_password.reverb_secret.result
  })
}
