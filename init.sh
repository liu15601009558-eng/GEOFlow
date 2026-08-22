#!/bin/bash
# GEOFlow 初始化脚本
# 用法: ./init.sh [command]
# 命令:
#   start    - 启动所有服务
#   stop     - 停止所有服务
#   restart  - 重启所有服务
#   status   - 查看服务状态
#   logs     - 查看日志
#   test     - 运行测试
#   deploy   - 部署更新

set -e

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# 项目目录
PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$PROJECT_DIR"

# 打印带颜色的消息
print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠ $1${NC}"
}

print_info() {
    echo -e "ℹ $1"
}

# 检查 Docker 是否运行
check_docker() {
    if ! docker info > /dev/null 2>&1; then
        print_error "Docker 未运行，请先启动 Docker"
        exit 1
    fi
    print_success "Docker 正在运行"
}

# 检查容器状态
check_containers() {
    local containers=("geo-system-web" "geo-system-queue" "geo-system-scheduler" "geo-system-postgres")
    local all_running=true
    
    for container in "${containers[@]}"; do
        if docker ps --format '{{.Names}}' | grep -q "^${container}$"; then
            print_success "$container 正在运行"
        else
            print_error "$container 未运行"
            all_running=false
        fi
    done
    
    if [ "$all_running" = false ]; then
        return 1
    fi
    return 0
}

# 启动服务
start_services() {
    print_info "启动 GEOFlow 服务..."
    
    # 检查 Docker
    check_docker
    
    # 启动容器
    docker compose up -d
    
    # 等待服务启动
    print_info "等待服务启动..."
    sleep 5
    
    # 检查容器状态
    if check_containers; then
        print_success "所有服务已启动"
    else
        print_error "部分服务启动失败"
        exit 1
    fi
}

# 停止服务
stop_services() {
    print_info "停止 GEOFlow 服务..."
    docker compose down
    print_success "所有服务已停止"
}

# 重启服务
restart_services() {
    print_info "重启 GEOFlow 服务..."
    stop_services
    start_services
}

# 查看服务状态
show_status() {
    print_info "GEOFlow 服务状态:"
    echo ""
    docker ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}" | grep -E "geo-system|NAMES"
    echo ""
    
    print_info "数据库状态:"
    docker exec geo-system-postgres pg_isready -U geo_user -d geo_system > /dev/null 2>&1 && \
        print_success "PostgreSQL 正常" || print_error "PostgreSQL 异常"
    
    print_info "最近任务运行:"
    docker exec geo-system-postgres psql -U geo_user -d geo_system -c \
        "SELECT id, task_id, status, started_at FROM task_runs ORDER BY id DESC LIMIT 5;" 2>/dev/null || \
        print_warning "无法查询数据库"
}

# 查看日志
show_logs() {
    local service=${1:-"all"}
    
    case $service in
        web)
            docker logs geo-system-web --tail 50
            ;;
        queue)
            docker logs geo-system-queue --tail 50
            ;;
        scheduler)
            docker logs geo-system-scheduler --tail 50
            ;;
        postgres)
            docker logs geo-system-postgres --tail 50
            ;;
        all)
            print_info "Web 日志:"
            docker logs geo-system-web --tail 20
            echo ""
            print_info "Queue 日志:"
            docker logs geo-system-queue --tail 20
            echo ""
            print_info "Scheduler 日志:"
            docker logs geo-system-scheduler --tail 20
            ;;
        *)
            print_error "未知服务: $service"
            print_info "可用服务: web, queue, scheduler, postgres, all"
            exit 1
            ;;
    esac
}

# 运行测试
run_tests() {
    print_info "运行 GEOFlow 测试..."
    
    # 检查容器是否运行
    if ! check_containers > /dev/null 2>&1; then
        print_error "服务未运行，请先启动服务"
        exit 1
    fi
    
    # 运行 Laravel 测试
    docker exec geo-system-web php /var/www/html/artisan test
    
    print_success "测试完成"
}

# 部署更新
deploy_update() {
    print_info "部署 GEOFlow 更新..."
    
    # 拉取最新代码
    print_info "拉取最新代码..."
    git pull origin main
    
    # 更新依赖
    print_info "更新 Composer 依赖..."
    docker exec geo-system-web composer install --no-dev --optimize-autoloader
    
    # 运行迁移
    print_info "运行数据库迁移..."
    docker exec geo-system-web php /var/www/html/artisan migrate --force
    
    # 清除缓存
    print_info "清除缓存..."
    docker exec geo-system-web php /var/www/html/artisan cache:clear
    docker exec geo-system-web php /var/www/html/artisan config:clear
    docker exec geo-system-web php /var/www/html/artisan route:clear
    docker exec geo-system-web php /var/www/html/artisan view:clear
    
    # 重启队列 worker
    print_info "重启队列 worker..."
    docker exec geo-system-queue php /var/www/html/artisan queue:restart
    
    print_success "部署完成"
}

# 显示帮助
show_help() {
    echo "GEOFlow 初始化脚本"
    echo ""
    echo "用法: ./init.sh [command]"
    echo ""
    echo "命令:"
    echo "  start    - 启动所有服务"
    echo "  stop     - 停止所有服务"
    echo "  restart  - 重启所有服务"
    echo "  status   - 查看服务状态"
    echo "  logs     - 查看日志 (可指定服务: web, queue, scheduler, postgres, all)"
    echo "  test     - 运行测试"
    echo "  deploy   - 部署更新"
    echo "  help     - 显示此帮助信息"
    echo ""
    echo "示例:"
    echo "  ./init.sh start"
    echo "  ./init.sh logs web"
    echo "  ./init.sh deploy"
}

# 主函数
main() {
    local command=${1:-"help"}
    
    case $command in
        start)
            start_services
            ;;
        stop)
            stop_services
            ;;
        restart)
            restart_services
            ;;
        status)
            show_status
            ;;
        logs)
            show_logs ${2:-"all"}
            ;;
        test)
            run_tests
            ;;
        deploy)
            deploy_update
            ;;
        help|--help|-h)
            show_help
            ;;
        *)
            print_error "未知命令: $command"
            show_help
            exit 1
            ;;
    esac
}

# 执行主函数
main "$@"
