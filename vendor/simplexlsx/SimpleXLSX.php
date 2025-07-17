<?php
/**
 * 简单的XLSX读取库
 * 用于WP Disk Link Manager插件
 */
class SimpleXLSX {
    
    private $data = array();
    private $error = '';
    
    public static function parse($filename) {
        $obj = new self();
        
        if (!extension_loaded('zip')) {
            $obj->error = 'PHP ZIP extension is required';
            return false;
        }
        
        if (!file_exists($filename)) {
            $obj->error = 'File not found';
            return false;
        }
        
        $zip = new ZipArchive();
        $result = $zip->open($filename);
        
        if ($result !== TRUE) {
            $obj->error = 'Cannot open XLSX file';
            return false;
        }
        
        try {
            // 读取共享字符串
            $shared_strings = array();
            $shared_strings_xml = $zip->getFromName('xl/sharedStrings.xml');
            if ($shared_strings_xml) {
                $xml = simplexml_load_string($shared_strings_xml);
                if ($xml) {
                    foreach ($xml->si as $si) {
                        $value = '';
                        if (isset($si->t)) {
                            $value = (string)$si->t;
                        } elseif (isset($si->r)) {
                            // 富文本格式
                            foreach ($si->r as $r) {
                                if (isset($r->t)) {
                                    $value .= (string)$r->t;
                                }
                            }
                        }
                        $shared_strings[] = $value;
                    }
                }
            }
            
            // 读取工作表数据
            $worksheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if (!$worksheet_xml) {
                $obj->error = 'Cannot read worksheet data';
                $zip->close();
                return false;
            }
            
            $xml = simplexml_load_string($worksheet_xml);
            if (!$xml) {
                $obj->error = 'Cannot parse worksheet XML';
                $zip->close();
                return false;
            }
            
            $rows = array();
            
            if (isset($xml->sheetData->row)) {
                foreach ($xml->sheetData->row as $row) {
                    $row_data = array();
                    $col_index = 0;
                    $max_col = 0;
                    
                    // 预处理单元格，获取最大列数
                    $cells = array();
                    if (isset($row->c)) {
                        foreach ($row->c as $cell) {
                            $cell_ref = (string)$cell['r']; // 如 A1, B1, C1
                            $col_letter = preg_replace('/[0-9]+/', '', $cell_ref);
                            $col_num = self::columnLetterToNumber($col_letter) - 1;
                            $cells[$col_num] = $cell;
                            $max_col = max($max_col, $col_num);
                        }
                    }
                    
                    // 填充行数据
                    for ($i = 0; $i <= $max_col; $i++) {
                        $value = '';
                        
                        if (isset($cells[$i])) {
                            $cell = $cells[$i];
                            
                            if (isset($cell->v)) {
                                if (isset($cell['t']) && (string)$cell['t'] === 's') {
                                    // 共享字符串
                                    $index = (int)$cell->v;
                                    $value = isset($shared_strings[$index]) ? $shared_strings[$index] : '';
                                } elseif (isset($cell['t']) && (string)$cell['t'] === 'inlineStr') {
                                    // 内联字符串
                                    if (isset($cell->is->t)) {
                                        $value = (string)$cell->is->t;
                                    }
                                } else {
                                    // 数字或其他类型
                                    $value = (string)$cell->v;
                                    
                                    // 尝试检测日期格式
                                    if (is_numeric($value) && $value > 25000) {
                                        // 可能是Excel日期序列号
                                        $unix_date = ($value - 25569) * 86400;
                                        if ($unix_date > 0) {
                                            $date = date('Y-m-d', $unix_date);
                                            if ($date !== '1970-01-01') {
                                                $value = $date;
                                            }
                                        }
                                    }
                                }
                            } elseif (isset($cell->f)) {
                                // 公式单元格，尝试获取计算值
                                $value = isset($cell->v) ? (string)$cell->v : '';
                            }
                        }
                        
                        $row_data[] = $value;
                    }
                    
                    $rows[] = $row_data;
                }
            }
            
            $obj->data = $rows;
            $zip->close();
            return $obj;
            
        } catch (Exception $e) {
            $obj->error = 'Error parsing XLSX: ' . $e->getMessage();
            $zip->close();
            return false;
        }
    }
    
    public function rows() {
        return $this->data;
    }
    
    public static function parseError() {
        return 'XLSX parsing error';
    }
    
    /**
     * 将列字母转换为数字
     */
    private static function columnLetterToNumber($letter) {
        $number = 0;
        $length = strlen($letter);
        
        for ($i = 0; $i < $length; $i++) {
            $number = $number * 26 + (ord($letter[$i]) - ord('A') + 1);
        }
        
        return $number;
    }
}