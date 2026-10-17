resource "aws_ecs_cluster" "this" {
  name = var.name
  setting {
    name  = "containerInsights"
    value = "enabled"
  }
}

resource "aws_cloudwatch_log_group" "this" {
  name              = "/ecs/${var.name}"
  retention_in_days = 30
}

data "aws_iam_policy_document" "ecs_assume" {
  statement {
    actions = ["sts:AssumeRole"]
    principals {
      type        = "Service"
      identifiers = ["ecs-tasks.amazonaws.com"]
    }
  }
}

resource "aws_iam_role" "execution" {
  name_prefix        = "${var.name}-exec-"
  assume_role_policy = data.aws_iam_policy_document.ecs_assume.json
}

resource "aws_iam_role_policy_attachment" "execution" {
  role       = aws_iam_role.execution.name
  policy_arn = "arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy"
}

resource "aws_iam_role_policy" "execution_secrets" {
  role = aws_iam_role.execution.id
  policy = jsonencode({
    Version   = "2012-10-17"
    Statement = [{ Effect = "Allow", Action = ["secretsmanager:GetSecretValue"], Resource = [aws_secretsmanager_secret.app.arn] }]
  })
}

# App tasks: S3 access + CloudWatch metrics (queue depth for capture autoscaling).
resource "aws_iam_role" "app" {
  name_prefix        = "${var.name}-app-"
  assume_role_policy = data.aws_iam_policy_document.ecs_assume.json
}

resource "aws_iam_role_policy" "app" {
  role = aws_iam_role.app.id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      { Effect = "Allow", Action = ["s3:GetObject", "s3:PutObject", "s3:DeleteObject"], Resource = ["${aws_s3_bucket.this.arn}/*"] },
      { Effect = "Allow", Action = ["s3:ListBucket"], Resource = [aws_s3_bucket.this.arn] },
      { Effect = "Allow", Action = ["cloudwatch:PutMetricData"], Resource = ["*"] },
    ]
  })
}

# Capture tasks get no AWS permissions at all.
resource "aws_iam_role" "capture" {
  name_prefix        = "${var.name}-capture-"
  assume_role_policy = data.aws_iam_policy_document.ecs_assume.json
}

locals {
  app_url      = "https://${var.domain_name}"
  internal_url = "http://web.${aws_service_discovery_private_dns_namespace.this.name}:8080"
  secret       = aws_secretsmanager_secret.app.arn

  app_environment = [
    { name = "APP_URL", value = local.app_url },
    { name = "APP_ENV", value = "production" },
    { name = "TRUSTED_PROXIES", value = var.vpc_cidr },
    { name = "TINTYPE_INTERNAL_URL", value = local.internal_url },
    { name = "DB_CONNECTION", value = "pgsql" },
    { name = "DB_HOST", value = aws_db_instance.this.address },
    { name = "DB_DATABASE", value = "tintype" },
    { name = "DB_USERNAME", value = "tintype" },
    { name = "REDIS_HOST", value = aws_elasticache_cluster.this.cache_nodes[0].address },
    { name = "REDIS_CLIENT", value = "predis" },
    { name = "QUEUE_CONNECTION", value = "redis" },
    { name = "CACHE_STORE", value = "redis" },
    { name = "SESSION_DRIVER", value = "redis" },
    { name = "BROADCAST_CONNECTION", value = "reverb" },
    { name = "REVERB_APP_ID", value = var.name },
    { name = "REVERB_HOST", value = "web.${aws_service_discovery_private_dns_namespace.this.name}" },
    { name = "REVERB_PORT", value = "8081" },
    { name = "REVERB_SCHEME", value = "http" },
    { name = "TINTYPE_REVERB_PUBLIC_HOST", value = var.domain_name },
    { name = "TINTYPE_REVERB_PUBLIC_PORT", value = "443" },
    { name = "TINTYPE_REVERB_PUBLIC_SCHEME", value = "https" },
    { name = "TINTYPE_DISK_DRIVER", value = "s3" },
    { name = "TINTYPE_S3_BUCKET", value = aws_s3_bucket.this.bucket },
    { name = "TINTYPE_S3_REGION", value = var.region },
    { name = "TINTYPE_OPEN_REGISTRATION", value = tostring(var.open_registration) },
    { name = "TINTYPE_CLOUDWATCH_NAMESPACE", value = "Tintype/${var.name}" },
  ]
  app_secrets = [for k in ["APP_KEY", "DB_PASSWORD", "TINTYPE_WORKER_TOKEN", "REVERB_APP_KEY", "REVERB_APP_SECRET"] :
    { name = k, valueFrom = "${local.secret}:${k}::" }
  ]

  roles = {
    web       = { command = ["web"], cpu = 1024, memory = 2048, count = var.web_count, ports = [8080, 8081] }
    horizon   = { command = ["horizon"], cpu = 512, memory = 1024, count = 1, ports = [] }
    scheduler = { command = ["scheduler"], cpu = 256, memory = 512, count = 1, ports = [] }
  }
}

resource "aws_ecs_task_definition" "app" {
  for_each                 = local.roles
  family                   = "${var.name}-${each.key}"
  requires_compatibilities = ["FARGATE"]
  network_mode             = "awsvpc"
  cpu                      = each.value.cpu
  memory                   = each.value.memory
  execution_role_arn       = aws_iam_role.execution.arn
  task_role_arn            = aws_iam_role.app.arn
  runtime_platform {
    operating_system_family = "LINUX"
    cpu_architecture        = "X86_64"
  }
  container_definitions = jsonencode(concat([{
    name         = each.key
    image        = var.web_image
    command      = each.value.command
    essential    = true
    environment  = local.app_environment
    secrets      = local.app_secrets
    portMappings = each.key == "web" ? [{ containerPort = 8080 }] : []
    logConfiguration = {
      logDriver = "awslogs"
      options   = { awslogs-group = aws_cloudwatch_log_group.this.name, awslogs-region = var.region, awslogs-stream-prefix = each.key }
    }
    }], each.key == "web" ? [{
    # Reverb runs alongside each web task so the ALB can route /app/* websockets to it.
    name         = "reverb"
    image        = var.web_image
    command      = ["reverb"]
    essential    = true
    environment  = local.app_environment
    secrets      = local.app_secrets
    portMappings = [{ containerPort = 8081 }]
    logConfiguration = {
      logDriver = "awslogs"
      options   = { awslogs-group = aws_cloudwatch_log_group.this.name, awslogs-region = var.region, awslogs-stream-prefix = "reverb" }
    }
  }] : []))
}

resource "aws_ecs_service" "app" {
  for_each        = local.roles
  name            = each.key
  cluster         = aws_ecs_cluster.this.id
  task_definition = aws_ecs_task_definition.app[each.key].arn
  desired_count   = each.value.count
  launch_type     = "FARGATE"
  network_configuration {
    subnets         = module.vpc.private_subnets
    security_groups = [aws_security_group.app.id]
  }
  dynamic "load_balancer" {
    for_each = each.key == "web" ? [1] : []
    content {
      target_group_arn = aws_lb_target_group.web.arn
      container_name   = "web"
      container_port   = 8080
    }
  }
  dynamic "load_balancer" {
    for_each = each.key == "web" ? [1] : []
    content {
      target_group_arn = aws_lb_target_group.reverb.arn
      container_name   = "reverb"
      container_port   = 8081
    }
  }
  dynamic "service_registries" {
    for_each = each.key == "web" ? [1] : []
    content { registry_arn = aws_service_discovery_service.web.arn }
  }
  deployment_circuit_breaker {
    enable   = true
    rollback = true
  }
}

# ---- Capture workers (one service per browser) ----

resource "aws_ecs_task_definition" "capture" {
  for_each                 = var.capture_images
  family                   = "${var.name}-capture-${each.key}"
  requires_compatibilities = ["FARGATE"]
  network_mode             = "awsvpc"
  cpu                      = var.capture_cpu
  memory                   = var.capture_memory
  execution_role_arn       = aws_iam_role.execution.arn
  task_role_arn            = aws_iam_role.capture.arn
  runtime_platform {
    operating_system_family = "LINUX"
    cpu_architecture        = "X86_64" # baselines must come from x86_64
  }
  container_definitions = jsonencode([{
    name      = "capture"
    image     = each.value
    command   = ["worker"]
    essential = true
    environment = [
      { name = "TINTYPE_SERVER", value = local.internal_url },
      { name = "TINTYPE_BROWSERS", value = each.key },
      { name = "TINTYPE_CONCURRENCY", value = "4" },
      { name = "TINTYPE_CAPTURE_IMAGE_TAG", value = each.value },
    ]
    secrets         = [{ name = "TINTYPE_WORKER_TOKEN", valueFrom = "${local.secret}:TINTYPE_WORKER_TOKEN::" }]
    linuxParameters = { initProcessEnabled = true }
    logConfiguration = {
      logDriver = "awslogs"
      options   = { awslogs-group = aws_cloudwatch_log_group.this.name, awslogs-region = var.region, awslogs-stream-prefix = "capture-${each.key}" }
    }
  }])
}

resource "aws_ecs_service" "capture" {
  for_each        = var.capture_images
  name            = "capture-${each.key}"
  cluster         = aws_ecs_cluster.this.id
  task_definition = aws_ecs_task_definition.capture[each.key].arn
  desired_count   = var.capture_min
  launch_type     = "FARGATE"
  network_configuration {
    subnets         = module.vpc.private_subnets
    security_groups = [aws_security_group.capture.id]
  }
  lifecycle { ignore_changes = [desired_count] }
}

resource "aws_appautoscaling_target" "capture" {
  for_each           = var.capture_images
  service_namespace  = "ecs"
  resource_id        = "service/${aws_ecs_cluster.this.name}/${aws_ecs_service.capture[each.key].name}"
  scalable_dimension = "ecs:service:DesiredCount"
  min_capacity       = var.capture_min
  max_capacity       = var.capture_max
}

# Scale on queued capture shards per worker (published by `tintype:metrics` every minute).
resource "aws_appautoscaling_policy" "capture" {
  for_each           = var.capture_images
  name               = "${var.name}-capture-${each.key}-queue"
  policy_type        = "TargetTrackingScaling"
  service_namespace  = "ecs"
  resource_id        = aws_appautoscaling_target.capture[each.key].resource_id
  scalable_dimension = aws_appautoscaling_target.capture[each.key].scalable_dimension
  target_tracking_scaling_policy_configuration {
    target_value       = 2
    scale_in_cooldown  = 300
    scale_out_cooldown = 30
    customized_metric_specification {
      metrics {
        id          = "backlog"
        expression  = "queued / IF(workers < 1, 1, workers)"
        return_data = true
      }
      metrics {
        id          = "queued"
        return_data = false
        metric_stat {
          stat = "Average"
          metric {
            namespace   = "Tintype/${var.name}"
            metric_name = "QueuedCaptureJobs"
            dimensions {
              name  = "Browser"
              value = each.key
            }
          }
        }
      }
      metrics {
        id          = "workers"
        return_data = false
        metric_stat {
          stat = "Average"
          metric {
            namespace   = "ECS/ContainerInsights"
            metric_name = "RunningTaskCount"
            dimensions {
              name  = "ClusterName"
              value = aws_ecs_cluster.this.name
            }
            dimensions {
              name  = "ServiceName"
              value = aws_ecs_service.capture[each.key].name
            }
          }
        }
      }
    }
  }
}
