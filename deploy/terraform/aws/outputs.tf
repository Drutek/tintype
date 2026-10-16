output "alb_dns_name" {
  description = "Point your domain (CNAME/ALIAS) here"
  value       = aws_lb.this.dns_name
}

output "url" {
  value = "https://${var.domain_name}"
}

output "bucket" {
  value = aws_s3_bucket.this.bucket
}

output "secrets_arn" {
  description = "Secrets Manager secret holding APP_KEY, DB password, worker token and Reverb keys"
  value       = aws_secretsmanager_secret.app.arn
}
