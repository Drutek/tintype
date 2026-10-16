variable "name" {
  description = "Name prefix for all resources"
  type        = string
  default     = "tintype"
}

variable "region" {
  type    = string
  default = "us-east-1"
}

variable "domain_name" {
  description = "Public hostname for Tintype (e.g. tintype.example.com). DNS must point at the ALB."
  type        = string
}

variable "certificate_arn" {
  description = "ACM certificate ARN covering domain_name"
  type        = string
}

variable "web_image" {
  type    = string
  default = "ghcr.io/drutek/tintype-web:latest"
}

variable "capture_images" {
  description = "Capture image per browser; only listed browsers get workers"
  type        = map(string)
  default = {
    chromium = "ghcr.io/drutek/tintype-capture-chromium:latest"
  }
}

variable "capture_min" {
  type    = number
  default = 1
}

variable "capture_max" {
  type    = number
  default = 10
}

variable "capture_cpu" {
  type    = number
  default = 2048
}

variable "capture_memory" {
  type    = number
  default = 4096
}

variable "web_count" {
  type    = number
  default = 2
}

variable "db_instance_class" {
  type    = string
  default = "db.t4g.medium"
}

variable "redis_node_type" {
  type    = string
  default = "cache.t4g.small"
}

variable "vpc_cidr" {
  type    = string
  default = "10.40.0.0/16"
}

variable "open_registration" {
  type    = bool
  default = false
}
