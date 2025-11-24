DROP TABLE IF EXISTS `sa_crontab`;
CREATE TABLE `sa_crontab`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `title` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '' COMMENT '任务标题',
  `type` varchar(10) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '' COMMENT '任务类型',
  `content` text CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL COMMENT '事件内容',
  `schedule` varchar(100) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '' COMMENT '执行周期(Crontab格式)',
  `maximums` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '最大执行次数 0为不限',
  `executes` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '已经执行的次数',
  `begintime` bigint(16) NULL DEFAULT NULL COMMENT '开始时间',
  `endtime` bigint(16) NULL DEFAULT NULL COMMENT '结束时间',
  `weigh` int(10) NOT NULL DEFAULT 0 COMMENT '权重(优先级)',
  `createtime` bigint(16) NULL DEFAULT NULL COMMENT '创建时间',
  `updatetime` bigint(16) NULL DEFAULT NULL COMMENT '更新时间',
  `executetime` bigint(16) NULL DEFAULT NULL COMMENT '最后执行时间',
  `status` enum('completed','expired','hidden','normal') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'normal' COMMENT '状态',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 7 CHARACTER SET = utf8 COLLATE = utf8_general_ci COMMENT = '定时任务表' ROW_FORMAT = DYNAMIC;

DROP TABLE IF EXISTS `sa_crontab_log`;
CREATE TABLE `sa_crontab_log`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `crontab_id` int(10) NULL DEFAULT NULL COMMENT '任务ID',
  `executetime` bigint(16) NULL DEFAULT NULL COMMENT '执行时间',
  `completetime` bigint(16) NULL DEFAULT NULL COMMENT '结束时间',
  `content` text CHARACTER SET utf8 COLLATE utf8_general_ci NULL COMMENT '执行结果',
  `processid` int(10) NULL DEFAULT 0 COMMENT '进程ID',
  `status` enum('success','failure','inprogress') CHARACTER SET utf8 COLLATE utf8_general_ci NULL DEFAULT 'failure' COMMENT '状态',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `crontab_id`(`crontab_id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 11028 CHARACTER SET = utf8 COLLATE = utf8_general_ci COMMENT = '定时任务日志表' ROW_FORMAT = DYNAMIC;