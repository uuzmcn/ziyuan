jQuery(document).ready(function($) {
    
    // Excel导入处理
    $('#excel-upload-form').on('submit', function(e) {
        e.preventDefault();
        
        var formData = new FormData();
        var fileInput = $('#excel_file')[0];
        
        if (!fileInput.files.length) {
            alert('请选择Excel文件');
            return;
        }
        
        var file = fileInput.files[0];
        var allowedTypes = ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        
        if (allowedTypes.indexOf(file.type) === -1) {
            alert('请选择.xls或.xlsx格式的Excel文件');
            return;
        }
        
        // 构建表单数据
        formData.append('action', 'upload_excel');
        formData.append('nonce', wpDiskLinkManagerAdmin.nonce);
        formData.append('excel_file', file);
        formData.append('skip_first_row', $('input[name="skip_first_row"]').is(':checked') ? '1' : '0');
        formData.append('post_status', $('input[name="post_status"]:checked').val());
        
        // 显示进度条
        showImportProgress();
        
        // 禁用表单
        $('#excel-upload-form input, #excel-upload-form button').prop('disabled', true);
        
        // 发送请求
        $.ajax({
            url: wpDiskLinkManagerAdmin.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            xhr: function() {
                var xhr = new window.XMLHttpRequest();
                
                // 上传进度
                xhr.upload.addEventListener("progress", function(evt) {
                    if (evt.lengthComputable) {
                        var percentComplete = evt.loaded / evt.total * 100;
                        updateProgress(percentComplete, '正在上传文件...');
                    }
                }, false);
                
                return xhr;
            },
            success: function(response) {
                if (response.success) {
                    updateProgress(100, '导入完成');
                    showImportResults(response.data);
                } else {
                    hideImportProgress();
                    showImportError(response.data || '导入失败');
                }
            },
            error: function(xhr, status, error) {
                hideImportProgress();
                showImportError('网络错误：' + error);
            },
            complete: function() {
                // 重新启用表单
                $('#excel-upload-form input, #excel-upload-form button').prop('disabled', false);
            }
        });
    });
    
    // 显示导入进度
    function showImportProgress() {
        $('#import-progress').show();
        $('#import-results').hide();
        updateProgress(0, '准备导入...');
    }
    
    // 隐藏导入进度
    function hideImportProgress() {
        $('#import-progress').hide();
    }
    
    // 更新进度条
    function updateProgress(percent, message) {
        $('.progress-fill').css('width', percent + '%');
        $('.progress-text').text(message + ' (' + Math.round(percent) + '%)');
    }
    
    // 显示导入结果
    function showImportResults(data) {
        hideImportProgress();
        
        var html = '<div class="import-success">';
        html += '<h4>导入成功完成</h4>';
        html += '<ul>';
        html += '<li>总计处理：' + data.total + ' 行</li>';
        html += '<li>成功导入：' + data.success_count + ' 篇文章</li>';
        
        if (data.errors && data.errors.length > 0) {
            html += '<li>失败：' + data.errors.length + ' 行</li>';
        }
        
        html += '</ul>';
        
        if (data.errors && data.errors.length > 0) {
            html += '<h5>错误详情：</h5>';
            html += '<ul class="error-list">';
            
            data.errors.forEach(function(error) {
                html += '<li>' + escapeHtml(error) + '</li>';
            });
            
            html += '</ul>';
        }
        
        html += '</div>';
        
        $('#import-results .import-summary').html(html);
        $('#import-results').show();
        
        // 重置表单
        $('#excel-upload-form')[0].reset();
    }
    
    // 显示导入错误
    function showImportError(message) {
        var html = '<div class="import-error">';
        html += '<h4>导入失败</h4>';
        html += '<p>' + escapeHtml(message) + '</p>';
        html += '</div>';
        
        $('#import-results .import-summary').html(html);
        $('#import-results').show();
    }
    
    // HTML转义
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // 文件选择变化处理
    $('#excel_file').on('change', function() {
        var file = this.files[0];
        if (file) {
            var fileSize = (file.size / 1024 / 1024).toFixed(2);
            var fileName = file.name;
            
            // 显示文件信息
            var info = '<p class="file-info">已选择文件：' + fileName + ' (' + fileSize + ' MB)</p>';
            $('.file-info').remove();
            $(this).closest('td').append(info);
            
            // 验证文件大小
            if (file.size > 10 * 1024 * 1024) { // 10MB
                alert('文件大小不能超过10MB');
                $(this).val('');
                $('.file-info').remove();
            }
        }
    });
    
    // 设置页面的Cookie测试功能
    $('.test-cookie-btn').on('click', function(e) {
        e.preventDefault();
        
        var $btn = $(this);
        var diskType = $btn.data('disk-type');
        var cookieField = '#wp_disk_link_manager_' + diskType + '_cookie';
        var cookie = $(cookieField).val();
        
        if (!cookie.trim()) {
            alert('请先填写Cookie');
            return;
        }
        
        $btn.prop('disabled', true).text('测试中...');
        
        $.ajax({
            url: wpDiskLinkManagerAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'test_disk_cookie',
                nonce: wpDiskLinkManagerAdmin.nonce,
                disk_type: diskType,
                cookie: cookie
            },
            success: function(response) {
                if (response.success) {
                    alert('Cookie测试成功：' + response.data.message);
                } else {
                    alert('Cookie测试失败：' + response.data);
                }
            },
            error: function() {
                alert('网络错误，无法测试Cookie');
            },
            complete: function() {
                $btn.prop('disabled', false).text('测试Cookie');
            }
        });
    });
    
    // 手动清理按钮
    $('.manual-cleanup-btn').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm('确定要立即清理过期的转存文件吗？')) {
            return;
        }
        
        var $btn = $(this);
        $btn.prop('disabled', true).text('清理中...');
        
        $.ajax({
            url: wpDiskLinkManagerAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'manual_cleanup',
                nonce: wpDiskLinkManagerAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert('清理完成：' + response.data.message);
                    location.reload(); // 刷新页面以更新统计数据
                } else {
                    alert('清理失败：' + response.data);
                }
            },
            error: function() {
                alert('网络错误，清理失败');
            },
            complete: function() {
                $btn.prop('disabled', false).text('立即清理');
            }
        });
    });
    
    // 日志页面的筛选功能
    $('.log-filter').on('change', function() {
        var action = $('#filter-action').val();
        var dateFrom = $('#filter-date-from').val();
        var dateTo = $('#filter-date-to').val();
        
        var url = window.location.href.split('?')[0] + '?page=wp-disk-link-manager-logs';
        var params = [];
        
        if (action) {
            params.push('action=' + encodeURIComponent(action));
        }
        if (dateFrom) {
            params.push('date_from=' + encodeURIComponent(dateFrom));
        }
        if (dateTo) {
            params.push('date_to=' + encodeURIComponent(dateTo));
        }
        
        if (params.length > 0) {
            url += '&' + params.join('&');
        }
        
        window.location.href = url;
    });
    
    // 批量操作
    $('.bulk-action-btn').on('click', function(e) {
        e.preventDefault();
        
        var action = $('.bulk-actions select').val();
        var selectedItems = $('.bulk-checkbox:checked');
        
        if (!action) {
            alert('请选择操作');
            return;
        }
        
        if (selectedItems.length === 0) {
            alert('请选择至少一项');
            return;
        }
        
        if (!confirm('确定要执行此操作吗？')) {
            return;
        }
        
        var ids = [];
        selectedItems.each(function() {
            ids.push($(this).val());
        });
        
        $.ajax({
            url: wpDiskLinkManagerAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'bulk_action',
                nonce: wpDiskLinkManagerAdmin.nonce,
                bulk_action: action,
                ids: ids
            },
            success: function(response) {
                if (response.success) {
                    alert('操作完成');
                    location.reload();
                } else {
                    alert('操作失败：' + response.data);
                }
            },
            error: function() {
                alert('网络错误');
            }
        });
    });
});