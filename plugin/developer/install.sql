/*
 Navicat Premium Data Transfer

 Source Server         : demo
 Source Server Type    : MySQL
 Source Server Version : 50737
 Source Host           : 127.0.0.1:3306
 Source Schema         : sademo

 Target Server Type    : MySQL
 Target Server Version : 50737
 File Encoding         : 65001

 Date: 19/07/2022 18:46:07
*/

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Table structure for __PREFIX__ceshi
-- ----------------------------
DROP TABLE IF EXISTS `__PREFIX__ceshi`;
CREATE TABLE `__PREFIX__ceshi`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '姓名;权栈',
  `sex` int(1) NULL DEFAULT NULL COMMENT '性别',
  `avatar` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '头像',
  `hobby` set('write','game','read') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT '' COMMENT '爱好',
  `text` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL COMMENT '内容页',
  `age` int(11) NULL DEFAULT NULL COMMENT '年龄',
  `tags` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '关键词',
  `album` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL COMMENT '相册;多文件上传必须为text类型',
  `stars` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '星级',
  `interest` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '签名',
  `week` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '星期',
  `birthday` int(11) NULL DEFAULT NULL COMMENT '生日;必须要int类型',
  `json` json NULL COMMENT '数组',
  `color` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '色彩',
  `lines` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '额度',
  `status` int(255) NULL DEFAULT NULL COMMENT '状态',
  `city` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '城市',
  `content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL COMMENT '内容;内容字段必须是longtext类型',
  `update_time` int(11) NULL DEFAULT NULL COMMENT '更新时间',
  `create_time` int(11) NULL DEFAULT NULL COMMENT '创建时间',
  `delete_time` int(11) NULL DEFAULT NULL COMMENT '软删除标识',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 2 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of __PREFIX__ceshi
-- ----------------------------
INSERT INTO `__PREFIX__ceshi` VALUES (1, '张三', 1, '/upload/avatars/f8e34ec67a2a0233_100x100.jpg', 'write,read', NULL, NULL, NULL, 'a:1:{i:0;a:2:{s:3:\"src\";s:44:\"/upload/avatars/f8e34ec67a2a0233_100x100.jpg\";s:5:\"title\";s:0:\"\";}}', '4', NULL, NULL, NULL, '{\"name\": \"hehe\"}', NULL, NULL, NULL, '北戴河区', '# Markdown编辑器\n', 1658058613, 1656980177, NULL);

-- ----------------------------
-- Table structure for __PREFIX__generate
-- ----------------------------
DROP TABLE IF EXISTS `__PREFIX__generate`;
CREATE TABLE `__PREFIX__generate`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '菜单标题',
  `pid` int(1) UNSIGNED NULL DEFAULT 0 COMMENT '顶级菜单',
  `table` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '数据库表',
  `force` enum('0','1') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT '0' COMMENT '强制覆盖',
  `plugin` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '所属插件',
  `auth` enum('0','1') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT '1' COMMENT '菜单鉴权',
  `create` enum('0','1') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT '1' COMMENT '生成菜单',
  `global` int(1) UNSIGNED NULL DEFAULT 0 COMMENT '全局模型',
  `icon` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '菜单图标',
  `listField` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '列表字段',
  `controller` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '控制器',
  `menus` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL COMMENT '菜单内容',
  `formName` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '表单名称',
  `formType` enum('0','1') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT '1' COMMENT '表单类型',
  `formDesign` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL COMMENT '表单内容',
  `width` varchar(6) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '表单宽度',
  `height` varchar(6) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '表单高度',
  `relation` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL COMMENT '关联数据',
  `status` enum('0','1') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT '0' COMMENT '生成状态',
  `update_time` int(11) NULL DEFAULT NULL COMMENT '更新时间',
  `create_time` int(11) NULL DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 2 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '代码生成器' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of __PREFIX__generate
-- ----------------------------
INSERT INTO `__PREFIX__generate` VALUES (1, '测试代码', 0, '__PREFIX__ceshi', '1', '', '1', '1', 0, 'layui-icon-android', 'name,sex,avatar,hobby,age,tags,stars,city,album,json', '/ceshi/index', 'a:5:{i:0;a:6:{s:5:\"title\";s:6:\"查看\";s:5:\"route\";s:11:\"ceshi:index\";s:6:\"router\";s:12:\"/ceshi/index\";s:8:\"template\";s:6:\"默认\";s:4:\"auth\";s:1:\"1\";s:4:\"type\";s:1:\"1\";}i:1;a:6:{s:5:\"title\";s:6:\"添加\";s:5:\"route\";s:9:\"ceshi:add\";s:6:\"router\";s:10:\"/ceshi/add\";s:8:\"template\";s:6:\"默认\";s:4:\"auth\";s:1:\"1\";s:4:\"type\";s:1:\"1\";}i:2;a:6:{s:5:\"title\";s:6:\"编辑\";s:5:\"route\";s:9:\"ceshi:zdy\";s:6:\"router\";s:10:\"/ceshi/zdy\";s:8:\"template\";s:6:\"默认\";s:4:\"auth\";s:1:\"1\";s:4:\"type\";s:1:\"1\";}i:3;a:6:{s:5:\"title\";s:6:\"删除\";s:5:\"route\";s:14:\"ceshi:xiaoMing\";s:6:\"router\";s:15:\"/ceshi/xiaoMing\";s:8:\"template\";s:6:\"默认\";s:4:\"auth\";s:1:\"1\";s:4:\"type\";s:1:\"2\";}i:4;a:6:{s:5:\"title\";s:6:\"状态\";s:5:\"route\";s:12:\"ceshi:status\";s:6:\"router\";s:13:\"/ceshi/status\";s:8:\"template\";s:6:\"默认\";s:4:\"auth\";s:1:\"1\";s:4:\"type\";s:1:\"2\";}}', 'form', '1', '[{\"index\":0,\"tag\":\"input\",\"label\":\"姓名\",\"name\":\"name\",\"type\":\"text\",\"placeholder\":\"请输入\",\"default\":\"\",\"labelwidth\":\"110\",\"width\":100,\"maxlength\":\"\",\"min\":0,\"max\":0,\"required\":false,\"readonly\":false,\"disabled\":false,\"labelhide\":false,\"lay_verify\":\"\"},{\"index\":2,\"tag\":\"radio\",\"name\":\"sex\",\"label\":\"性别\",\"labelwidth\":110,\"width\":100,\"disabled\":false,\"labelhide\":false,\"options\":[{\"title\":\"男\",\"value\":\"1\",\"checked\":true},{\"title\":\"女\",\"value\":\"0\",\"checked\":false}]},{\"index\":3,\"tag\":\"upload\",\"name\":\"avatar\",\"label\":\"用户头像\",\"uploadtype\":\"images\",\"labelwidth\":110,\"width\":100,\"data_size\":102400,\"data_accept\":\"file\",\"disabled\":false,\"required\":false,\"labelhide\":false},{\"index\":7,\"tag\":\"upload\",\"name\":\"album\",\"label\":\"相册\",\"uploadtype\":\"multiple\",\"labelwidth\":110,\"width\":100,\"data_size\":102400,\"data_accept\":\"file\",\"disabled\":false,\"required\":false,\"labelhide\":false},{\"index\":8,\"tag\":\"rate\",\"name\":\"stars\",\"label\":\"星级\",\"labelwidth\":110,\"width\":100,\"data_default\":1,\"data_length\":5,\"data_half\":false,\"data_theme\":\"#1890ff\",\"readonly\":false,\"labelhide\":false},{\"index\":5,\"tag\":\"cascader\",\"name\":\"city\",\"label\":\"城市\",\"data_value\":\"label\",\"labelwidth\":110,\"width\":100,\"data_parents\":true,\"labelhide\":false},{\"index\":4,\"tag\":\"checkbox\",\"name\":\"hobby\",\"label\":\"爱好\",\"lay_skin\":\"primary\",\"labelwidth\":110,\"width\":100,\"disabled\":false,\"labelhide\":false,\"options\":[{\"title\":\"写作\",\"value\":\"write\",\"checked\":true},{\"title\":\"阅读\",\"value\":\"read\",\"checked\":true},{\"title\":\"游戏\",\"value\":\"game\",\"checked\":false}]},{\"index\":6,\"tag\":\"json\",\"name\":\"json\",\"label\":\"数组组件\",\"labelwidth\":110,\"width\":100,\"labelhide\":false},{\"index\":7,\"tag\":\"editor\",\"name\":\"content\",\"label\":\"编辑器\",\"editorType\":\"lay-editor\",\"labelwidth\":110,\"width\":100,\"labelhide\":false}]', '1200px', '900px', 'a:1:{i:0;a:5:{s:5:\"table\";s:7:\"__PREFIX__user\";s:5:\"style\";s:6:\"hasOne\";s:10:\"foreignKey\";s:8:\"group_id\";s:8:\"localKey\";s:2:\"id\";s:13:\"relationField\";s:12:\"group_id,pwd\";}}', '0', 1658227430, 1646395278);

-- ----------------------------
-- Table structure for __PREFIX__plugin
-- ----------------------------
DROP TABLE IF EXISTS `__PREFIX__plugin`;
CREATE TABLE `__PREFIX__plugin`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '标识',
  `title` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '插件名称',
  `intro` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '插件简介',
  `icon` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '插件图标',
  `author` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '插件作者',
  `home` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '前台主页',
  `version` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '插件版本',
  `config` int(1) UNSIGNED NULL DEFAULT 0 COMMENT '是否配置',
  `menu` int(1) UNSIGNED NULL DEFAULT 1 COMMENT '后台菜单',
  `import` int(11) UNSIGNED NULL DEFAULT 0 COMMENT '导入时间戳',
  `status` int(1) UNSIGNED NULL DEFAULT 1 COMMENT '当前状态',
  `create_time` int(11) NULL DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '插件开发助手' ROW_FORMAT = DYNAMIC;

-- ----------------------------
-- Records of __PREFIX__plugin
-- ----------------------------

SET FOREIGN_KEY_CHECKS = 1;