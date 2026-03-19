#!/bin/zsh
cd /Users/user/Documents/PhpStorm/unicontent/modules/wordpress
git add -A
git commit -m "Update plugin" 2>/dev/null || echo "Нечего коммитить"
git push origin main
echo ""
echo "✅ Плагин запушен на GitHub"
echo "Нажмите Enter для закрытия..."
read
