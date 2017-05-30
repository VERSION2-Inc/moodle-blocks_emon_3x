<?php
$moodles = new MoodlesManager();

define('QUIZ_TYPE_CHOICE', 'multichoice-single');
define('QUIZ_TYPE_MULTICHOICE', 'multichoice');
define('QUIZ_TYPE_TEXT', 'shortanswer');
define('QUIZ_TYPE_FILL', 'multianswer');
define('QUIZ_TYPE_TRUEFALSE', 'truefalse');
define('QUIZ_TYPE_MATCH', 'match');

class MoodlesManager
{
    function setQuestion($params)
    {
        global $converters;

        if (is_array($params['option'])) {
            // 登録データの生成
            $methods = array(
                QUIZ_TYPE_CHOICE => 'convertChoiceFormToAPI',
                QUIZ_TYPE_MULTICHOICE => 'convertMultichoiceFormToAPI',
                QUIZ_TYPE_TEXT => 'convertTextFormToAPI',
                QUIZ_TYPE_FILL => 'convertFillFormToAPI',
                QUIZ_TYPE_TRUEFALSE => 'convertTruefalseFormToAPI',
                QUIZ_TYPE_MATCH => 'convertMatchFormToAPI',
            );
            $option = $params['option'];
            if ($methods[$params['qtype']]) {
                $converters->{$methods[$params['qtype']]}($params, $option);
            }
        }

        // 新規or修正
        if ($params['questionid'] > 0) {
            $questionId = $this->setMoodleQuestion($params['questionid'], $params);
        } else {
            $questionId = $this->setMoodleQuestion(null, $params);
            // 問題の並び順を設定
            $pages = $this->getMoodleQuestions($params['cmid']);
            // 新規追加後は最後のページに追加されているので削除する
            unset($pages[count($pages)]['questions'][count($pages[count($pages)]['questions']) - 1]);

            // 追加 or 挿入
            if ($params['question_number'] < 0) {
                // 追加
                $pages[$params['page_number']]['questions'][] = $questionId;
            } else {
                // 挿入
                if (!isset($pages[$params['page_number']])) {
                    $pages[$params['page_number']]['questions'] = array();
                }
                $pages[$params['page_number']]['question'] = array_splice($pages[$params['page_number']]['questions'], $params['question_number'], 0, $questionId);
            }

            $this->setPageOrders($params['cmid'], $pages, $params);
        }

        list($thispageurl, $contexts, $cmid, $cm, $quiz, $pagevars) = question_edit_setup('editq', '/mod/quiz/edit.php', true);

        quiz_delete_previews($quiz);
        quiz_update_sumgrades($quiz);
        quiz_update_all_attempt_sumgrades($quiz);
        quiz_update_all_final_grades($quiz);
        quiz_update_grades($quiz, 0, true);

    }

    /**
     * ファイルをアップロード
     *
     * @param	int	$cmid
     * @param	string	$filename
     * @return	array
     */
    function uploadFile($cmid, $filename)
    {
        // 書き込み
        $post = array(
            'action' => 'upload_file',
            'cmid' => $cmid,
            'filename' => $filename
        );

        if ($result = $this->connect($this->moodleurl, $post)) {
            if ($result['result'] == 'ok') {
                return $result['params'];
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    /**
     * ページの並び順を保存する
     *
     * @param	int	$cmid
     * @param	array	$pages		array(ページ番号 => array('questions' => array(問題ID)))
     * @param	array	$post
     * @return	array	最新の番号順
     */
    function setPageOrders($cmid, $pages, $post = array())
    {
        global $CFG, $DB;

        // Edit by Minh
        if ($CFG->branch < 28)
        {
            require_once($CFG->dirroot.'/mod/quiz/editlib.php');
        } else {
            require_once($CFG->dirroot . '/question/editlib.php');
        }
        // End
        if ($CFG->branch >= 30) {
            list($thispageurl, $contexts, $cmid, $cm, $quiz, $pagevars) = question_edit_setup('editq', true);
            if (!empty($post)) {

                $pagenumber = isset($post['page_number']) ? $post['page_number'] : 1;
                $questionnumber = isset($post['question_number']) ? $post['question_number'] : -1;
                $questionbeforesort = $this->get_questions_slot($quiz->id, $pagenumber);

                if ($questionnumber >= 0) {
                    $last = count($questionbeforesort[1]) - 1;
                    $newpos = $questionnumber;
                    $this->array_move($questionbeforesort[1], $last, $newpos);
                    $reorder = $questionbeforesort[1];
                    $this->update_order($questionbeforesort[0], $reorder, $quiz->id);
                } else if (isset($post['drog_sort']) && !empty($post['drog_sort']) && isset($post['question_ids']) && !empty($post['question_ids'])) {
                    if ($questionbeforesort[1] != $post['question_ids']) {
                        $this->update_order_for_drag_question($questionbeforesort[0], $post['question_ids'], $quiz->id);
                    }
                } else {
                    if (!empty($post['mode'])) {
                        $this->update_slot_questions($quiz, $post);
                    }
                }
            } else {
                $slotreorder = $this->update_question_for_pages($pages, $quiz->id);
                if ($slotreorder) {
                    $currentslots = $this->get_questions_new($quiz->id);
                    if (!empty($currentslots) && !empty($slotreorder)) {
                        $this->update_slot_for_all_questions($currentslots, $slotreorder, $quiz->id);
                    }
                }
            }
            $this->delete_quiz_attempts_preview($quiz);
            return true;
        }

        // 配列の設定
        $overwritePages = array();
        if (is_array($pages)) {
            foreach ($pages as $number => $p) {
                if (is_array($p['questions'])) {
                    foreach ($p['questions'] as $pp) {
                        $overwritePages[] = $pp;
                    }
                }
                $overwritePages[] = 0;
            }
        }

        // 書き込み
        list($thispageurl, $contexts, $cmid, $cm, $quiz, $pagevars) = question_edit_setup('editq', true);

        // set question order
        $questions = array();
        foreach ($overwritePages as $number => $question) {
            if ((strlen(trim($question)) && array_search($question, $overwritePages) == $number) || $question == 0) {
                $questions[] = $question;
            }
        }
        $quiz->questions = implode(",", $questions);
        $quiz->questions = $quiz->questions . ',0';
        // Avoid duplicate page breaks
        while (strpos($quiz->questions, ',0,0')) {
            $quiz->questions = str_replace(',0,0', ',0', $quiz->questions);
        }

        if ($CFG->branch < 27)
        {
            if (!$DB->set_field('quiz', 'questions', $quiz->questions, array('id' => $quiz->id))) {
                print_error('Could not save question list');
            }
        }

        $this->delete_quiz_attempts_preview($quiz);

        return true;
    }

    public function delete_quiz_attempts_preview($quiz) {
        // preview delete
        global $DB;
        $previewattempts = $DB->get_records_select('quiz_attempts', 'quiz = ? AND preview = 1', array($quiz->id));
        if ($previewattempts) {
            foreach ($previewattempts as $attempt) {
                quiz_delete_attempt($attempt, $quiz);
            }
        }
    }

    /**
     * Add questions to pages
     *
     * @param array $pages
     * @param int $quizid
     * @return array|bool
     */
    private function update_question_for_pages($pages, $quizid) {
        if (empty($pages) || !is_array($pages)) {
            return false;
        }
        $pageindex = 1;
        $slotreorder = array();
        foreach ($pages as $page) {
            if (!empty($page['questions']) && is_array($page['questions'])) {
                $slotreorder = array_merge($slotreorder, $page['questions']);
                $this->update_page_for_questions($page['questions'], $pageindex, $quizid);
            }
            $pageindex++;
        }
        return $slotreorder;
    }
    /**
     * Update page for questions
     *
     * @param array $questions
     * @param int $pagenumber
     * @param int $quizid
     * @return bool
     */
    private function update_page_for_questions($questions, $pagenumber, $quizid) {
        if (empty($questions) || !is_array($questions)) {
            return false;
        }
        global $DB;
        foreach ($questions as $question) {
            $DB->set_field('quiz_slots', 'page', $pagenumber, array('quizid' => $quizid, 'questionid' => $question));
        }
        return true;
    }


    /**
     * 問題の削除
     *
     * @param	int	$questionId
     * @return	array
     */
    function removeMoodleQuestion($cmid, $questionId, $sesskey)
    {
        global $CFG, $DB;

        // Edit by Minh
        if ($CFG->branch < 28)
        {
            require_once($CFG->dirroot.'/mod/quiz/editlib.php');
        } else {
            require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        }
        // End

        list($thispageurl, $contexts, $cmid, $cm, $quiz, $pagevars) = question_edit_setup('editq', true);

        if (confirm_sesskey($sesskey)) {

            // Edit by Minh
            if ($CFG->branch < 27)
            {
                if (!quiz_remove_question($quiz, $questionId)) {
                    return false;
                }
            } elseif ($CFG->branch < 28) {
                $sql = '
                        SELECT slot FROM {quiz_slots}
                        WHERE quizid = :quizid AND questionid = :questionid
                ';

                $params = array(
                    'quizid' => $quiz->id,
                    'questionid' => $questionId,
                );

                $slotNumber = $DB->get_field_sql($sql, $params);

                if (!quiz_remove_slot($quiz, $slotNumber)) {
                    return false;
                }
            } else {
                $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course);
                $course = $DB->get_record('course', array('id' => $quiz->course), '*', MUST_EXIST);
                require_login($course, false, $cm);

                $quizobj = new quiz($quiz, $cm, $course);
                $structure = $quizobj->get_structure();

                $sql = '
                        SELECT slot FROM {quiz_slots}
                        WHERE quizid = :quizid AND questionid = :questionid
                ';

                $params = array(
                    'quizid' => $quiz->id,
                    'questionid' => $questionId,
                );

                $slotNumber = $DB->get_field_sql($sql, $params);

                if ($CFG->branch >= 30) {
                    if (!$structure->remove_slot($slotNumber)) {
                        return false;
                    }
                } else {
                    if (!$structure->remove_slot($quiz, $slotNumber)) {
                        return false;
                    }
                }
            }
            // End
        } else {
            return false;
        }

        $this->delete_quiz_attempts_preview($quiz);

        return true;
    }

    /**
     * 問題のコピー
     *
     * @param	int	$questionId
     * @return	array
     */
    function copyMoodleQuestion($questionId)
    {
        $post = array(
            'action' => 'copy_question',
            'questionid' => $questionId
        );

        // set
        if ($result = $this->connect($this->moodleurl, $post)) {
            if ($result['result'] == 'ok') {
                return $result;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    /**
     * 問題の取得
     *
     * @param	int	$questionId
     * @return	array
     */
    function getMoodleQuestion($questionId)
    {
        global $CFG, $DB;

        // library
        // Edit by Minh
        if ($CFG->branch < 28)
        {
            require_once($CFG->dirroot.'/mod/quiz/editlib.php');
        }
        // End

        require_once($CFG->dirroot.'/question/editlib.php');

        // get question categories
        if (!$question = $DB->get_record('question', array('id' => $questionId))) {
            print_error('question does not find');
        }
        get_question_options($question);

        if ($question->qtype == QUIZ_TYPE_MULTICHOICE && $question->options->single == 1) {
            $question->qtype = QUIZ_TYPE_CHOICE;
        }
        return $question;
    }

    /**
     * ページ内の問題一覧を取得
     *
     * @param	int	$cmid
     * @param	int	$pageNumber
     * @return	array[]
     */
    function getMoodleQuestionsFromPageNumber($cmid, $pageNumber)
    {
        $pages = $this->getMoodleQuestions($cmid);
        $questionIds = @$pages[$pageNumber]['questions'];

        $questions = array();
        if (is_array($questionIds)) {
            foreach ($questionIds as $id) {
                // 問題の取得
                $questions[] = $this->getMoodleQuestion($id);
            }
        }

        return $questions;
    }

    /**
     * セクション一覧の取得
     *
     * @param	int $courseId
     * @return	array
     */
    function getMoodleCourseSections($courseId)
    {
        global $CFG, $DB;

        // library
        include_once($CFG->dirroot . '/course/lib.php');

        // error check
        if (empty($courseId)) {
            print_error('Must specify course id, short name or idnumber(couse_idが脱落している可能性があります。)');
        }
        if (!($course = $DB->get_record('course', array('id' => $courseId), '*', MUST_EXIST)) ) {
            print_error('Invalid course id');
        }
        context_helper::preload_course($course->id);
        if (!$context = context_course::instance($course->id)) {
            print_error('nocontext');
        }
        require_login($course);

        // capability check
        if (!has_capability('moodle/course:manageactivities', $context)) {
            print_error('You do not have capability of this course');
        }

        // get course sections
        $sections = get_fast_modinfo($course->id)->get_section_info_all();

        $jsonSections = array();
        foreach ($sections as $number => $section) {
            $jsonSections[$section->section] = $section->id;
        }
        ksort($jsonSections);
        unset($jsonSections[0]);

        error_log(print_r($jsonSections,true));

        return $jsonSections;
    }

    /**
     * 問題解答状況の取得
     *
     * @param	int	$cmid
     * @return	bool
     */
    function getMoodleIsAttempt($cmid)
    {
        global $CFG, $DB;
        $questions = array();

        // library
        // Edit by Minh
        if ($CFG->branch < 28)
        {
            require_once($CFG->dirroot . '/mod/quiz/editlib.php');
        } else {
            require_once($CFG->dirroot . '/question/editlib.php');
        }
        // End

        // parameters
        $cmid = required_param('cmid', PARAM_INT);
        list($thispageurl, $contexts, $cmid, $cm, $quiz, $pagevars) = question_edit_setup('editq', true);

        // error check
        if (! $course = $DB->get_record("course", array("id" => $quiz->course))) {
            print_error("This course doesn't exist");
        }

        // capability check
        require_capability('mod/quiz:manage', $contexts->lowest());

        // already attempts check
        $isAttempts = false;
        if (isset($quiz->instance) and $a= $DB->record_exists_select('quiz_attempts', "quiz = ? AND preview = '0'", array($quiz->instance))) {
            $isAttempts = true;
        }

        return $isAttempts;
    }

    /**
     * 問題一覧の取得
     *
     * @param	int	$cmid
     * @return	array		ページ番号(1～):array('questions' => array(問題ID), 'page_number' => int, 'question_count' => int)
     */
    function getMoodleQuestions($cmid)
    {
        global $CFG, $DB;
        $questions = array();

        // library
        // Edit by Minh
        if ($CFG->branch < 28)
        {
            require_once($CFG->dirroot . '/mod/quiz/editlib.php');
        } else {
            require_once($CFG->dirroot . '/question/editlib.php');
        }
        // End

        // parameters
        list($thispageurl, $contexts, $cmid, $cm, $quiz, $pagevars) = question_edit_setup('editq', true);

        // error check
        if (! $course = $DB->get_record("course", array("id" => $quiz->course))) {
            print_error("This course doesn't exist");
        }

        // capability check
        require_capability('mod/quiz:manage', $contexts->lowest());

        // get question categories
        $contexts = $contexts->having_one_edit_tab_cap('editq');
        $pages = array();

        if ($CFG->branch < 27)
        {
            $questions = explode(',', $quiz->questions);
            $pageNumber = 1;
            foreach ($questions as $q) {
                if (!isset($pages[$pageNumber]['question_count'])) {
                    $pages[$pageNumber]['question_count'] = 0;
                }
                $pages[$pageNumber]['page_number'] = $pageNumber;

                if ($q > 0) {
                    $pages[$pageNumber]['questions'][] = $q;
                    $pages[$pageNumber]['question_count']++;
                } else {
                    $pageNumber++;
                }
            }
        } else {
            $questions = $DB->get_records_select('quiz_slots', 'quizid = ? ', array($quiz->id), 'page, slot ASC', 'questionid, page');
            foreach ($questions as $q) {
                $pagenumber = $q->page ? $q->page : 1;
                if (!isset($pages[$pagenumber]['question_count'])) {
                    $pages[$pagenumber]['question_count'] = 0;
                }
                $pages[$pagenumber]['page_number'] = $pagenumber;
                $pages[$pagenumber]['questions'][] = $q->questionid;
                $pages[$pagenumber]['question_count']++;
            }
        }

        return $pages;
    }

    /**
     * 問題の設定
     *
     * @param	int	$questionId
     * @param	array	$post
     * @return	int
     */
    function setMoodleQuestion($questionId, $post)
    {
        global $CFG, $DB;

        // library
        require_once($CFG->dirroot.'/question/editlib.php');

        // Edit by Minh
        if ($CFG->branch < 28)
        {
            require_once($CFG->dirroot.'/mod/quiz/editlib.php');
        } else {
            require_once($CFG->dirroot.'/mod/quiz/locallib.php');
        }
        // End

        require_once($CFG->libdir.'/filelib.php');

        // parameters
        $cmid = $post['cmid'];
        $id = $post['questionid'];

        $question = new stdClass();
        $question->cmid = $cmid;
        $question->id = $id;
        $question->courseid = $post['course'];

        if ($id > 0) {
            // get exists question data
            if (!$question = $DB->get_record('question', array('id' => $id))) {
                print_error('questiondoesnotexist');
            }
            get_question_options($question);
        } else {
            $question->questiontextformat = 1;
            $question->penalty = 0.1;
        }

        $question->qtype = $post['qtype'];
        $question->category = $post['category'];
        $question->questiontext = array(
            'text' => $post['questiontext'],
            'format' => 1,
            'itemid' => $post['itemid']
        );
        $question->generalfeedback = array(
            'text' => $post['generalfeedback'],
            'format' => 1
        );
        $question->defaultmark = $post['defaultgrade'];

        // common default parameter
        // $question->name = mb_substr(strip_tags($question->questiontext), 0, 24) . SYSTEM_NAME;
        // specification change
        $question->name = $post['name'];

        // set question
        // multichoice single
        if ($question->qtype == 'multichoice-single') {
            $question->qtype = 'multichoice';
            $question->single = 1;
            if (is_array($post['answer'])) {
                foreach ($post['answer'] as $k => $a) {
                    $question->answer[$k] = array(
                        'text' => $a,
                        'format' => 1
                    );
                }
            }
            $correct = $post['correct'];

            // 既存問題のパラメーター
            $fractions = array();
            $feedbacks = array();
            if ($id) {
                // 既存問題のパラメーター
                $number = 0;
                foreach ($question->options->answers as $answer) {
                    $fractions[$number] = $answer->fraction;
                    $feedbacks[$number] = $answer->feedback;
                    $number++;
                }
            }

            if (is_array($question->answer)) {
                foreach ($question->answer as $k => $v) {
                    $question->fraction[$k] = ($correct === $k) ? 1 : 0;
                    if (!$id) {
                        $question->feedback[$k] = array(
                            'text' => '',
                            'format' => 1,
                        );
                    } else {
                        $question->feedback[$k] = array(
                            'text' => isset($feedbacks[$k]) ? $feedbacks[$k] : '',
                            'format' => 1
                        );
                    }
                }
            }
            $question->shuffleanswers = $post['shuffleanswers'];
            if (!$id) {
                $question->answernumbering = 'none';
            } else {
                $question->answernumbering = $question->options->answernumbering;
            }
        }

        // multichoice(multi)
        else if ($question->qtype == 'multichoice' && empty($question->single)) {
            $question->single = 0;
            if (is_array($post['answer'])) {
                foreach ($post['answer'] as $k => $a) {
                    $question->answer[$k] = array(
                        'text' => $a,
                        'format' => 1
                    );
                }
            }
            $corrects = $post['corrects'];
            // 正解の個数
            $correct = 0;
            foreach ($corrects as $c) {
                if ($c) {
                    $correct++;
                }
            }

            // 既存問題のパラメーター
            $fractions = array();
            $feedbacks = array();
            if ($id) {
                // 既存問題のパラメーター
                $answers = $question->options->answers;
                $number = 0;
                foreach ($answers as $answer) {
                    $fractions[$number] = $answer->fraction;
                    $feedbacks[$number] = $answer->feedback;
                    $number++;
                }
            }

            $number = 0;
            foreach ($question->answer as $k => $v) {
                $question->fraction[$number] = (isset($corrects[$number + 1]) && $corrects[$number + 1]) ? (1 / $correct) : (-1 / (count($question->answer) - $correct));
                if (!$id) {
                    $question->feedback[$number] = array(
                        'text' => '',
                        'format' => 1
                    );
                } else {
                    $question->feedback[$number] = array(
                        'text' => $feedbacks[$number],
                        'format' => 1
                    );
                }
                $number++;
            }
            $question->shuffleanswers = $post['shuffleanswers'];
            if (!$id) {
                $question->answernumbering = 'none';
            } else {
                $question->answernumbering = $question->options->answernumbering;
            }
        }

        // shortanswer
        else if ($question->qtype == 'shortanswer') {
            $question->answer = $post['answer'];

            // 既存問題のパラメーター
            if ($id) {
                // 既存問題のパラメーター
                $answers = $question->options->answers;
                $number = 0;
                foreach ($answers as $answer) {
                    $fractions[$number] = $answer->fraction;
                    $feedbacks[$number] = $answer->feedback;
                    $number++;
                }
            }

            foreach ($question->answer as $k => $v) {
                $question->fraction[$k] = 1;
                $question->feedback[$k] = array(
                    'text' => '',
                    'format' => 1,
                );
            }
            $question->usecase = $post['usecase'];
        }

        // multianswer
        else if ($question->qtype == 'multianswer') {
            $questions = $post['questions'];
            $question->options = new stdClass;
            $question->options->questions = array();
            foreach ($questions as $k => $q) {
                $question->options->questions[$k] = new stdClass;
                $question->options->questions[$k]->qtype = $post['qtype'];
                $question->options->questions[$k]->questiontext = $post['questiontext'];
                $question->options->questions[$k]->answers = array();
                $question->options->questions[$k]->fraction = array();
                $question->options->questions[$k]->feedback = array();
                foreach ($post['option']['answers'] as $kk => $a) {
                    $question->options->questions[$k]->answers[$kk] = $a;
                    if ($post['qtype'] == 'multichoice') {
                        if ($k == $q['correct']) {
                            $question->options->questions[$k]->fraction[$k] = 1;
                        }
                    } else {
                        $question->options->questions[$k]->fraction[$k] = 1;
                    }
                    $question->options->questions[$k]->feedback[$k] = '';
                }
            }
        }

        // truefalse
        else if ($question->qtype == 'truefalse') {
            $question->answer = $post['answer'];
            $correct = $post['correct'];
            $feedback = $post['feedback'];

            // 既存問題のパラメーター
            if ($id) {
                // 既存問題のパラメーター
                $answers = $question->options->answers;
                $number = 0;
                foreach ($answers as $answer) {
                    $fractions[$number] = $answer->fraction;
                    $feedbacks[$number] = $answer->feedback;
                    $number++;
                }
            }

            $question->correctanswer = $correct;
            $question->feedbacktrue = array(
                'text' => $feedback[0],
                'format' => 1
            );
            $question->feedbackfalse = array(
                'text' => $feedback[1],
                'format' => 1
            );
        }

        // match
        // 組み合わせ問題
        else if ($question->qtype == 'match') {
            //詰め込みをやりなおす
            $question->questiontext = array(
                'text' => $post['questiontext'],
                'format' => 1,
                'itemid' => $post['itemid']
            );

            $key = 0;
            $question->subquestions = $post['subquestions'];
            //subquestionだけ、[$key]['text']にしないといけない。
            foreach ($question->subquestions as $subquestion) {
                $question->subquestions[$key] = array();
                $question->subquestions[$key]['text'] = $subquestion;
                //$question->subquestions[$key]['format'] = $subquestion->questiontextformat;
                $question->subquestions[$key]['format'] = 1;
                $key++;
            }

            $question->subanswers = $post['subanswers'];
            $question->shuffleanswers = $post['shuffleanswers'];

        }

        // feedbacks
        if (!$id) {
            $question->correctfeedback = array(
                'text' => '',
                'format' => 1
            );
            $question->partiallycorrectfeedback = array(
                'text' => '',
                'format' => 1
            );
            $question->incorrectfeedback = array(
                'text' => '',
                'format' => 1
            );
        } else {
            $question->correctfeedback = array(
                'text' => @$question->options->correctfeedback,
                'format' => 1
            );
            $question->partiallycorrectfeedback = array(
                'text' => @$question->options->partiallycorrectfeedback,
                'format' => 1
            );
            $question->incorrectfeedback = array(
                'text' => @$question->options->incorrectfeedback,
                'format' => 1
            );
        }

        // save questions
        //PHP関数のcloneではなく、Moodleで用意されているfullclone関数を使う。
        $form = fullclone($question);

        $qtypeobj = question_bank::get_qtype($question->qtype);
        $question = $qtypeobj->save_question($question, $form);

        // set question to quiz
        if (!$id) {
            list($module, $cm) = get_module_from_cmid($cmid);

            $pageNumber = $post['page_number'] ? (int)$post['page_number'] : 0;
            quiz_add_quiz_question($question->id, $module, $pageNumber);
        }

        question_bank::notify_question_edited($question->id);

        /*
         // preview delete
        $previewattempts = $DB->get_records_select('quiz_attempts',
                'quiz = ? AND preview = 1', array($module->id));
        if ($previewattempts) {
        foreach ($previewattempts as $attempt) {
        quiz_delete_attempt($attempt, $quiz);
        }
        }
        */

        return $question->id;
    }

    /**
     * クイズ設定
     *
     * @param	array	$post  (仕様書に従ったPOST変数: この関数内で整形する)
     * @return	array
     */
    function setMoodleQuiz($post)
    {
        // validate
        $post['action'] = 'set_quiz';
        if (!$post['cmid']) {
            unset($post['cmid']);
        }

        // set
        if ($result = $this->connect($this->moodleurl, $post)) {
            if ($result['result'] == 'ok') {
                return $result;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    /**
     * ユーザー情報の取得
     *
     * @return	array
     */
    function setMoodleUserSession()
    {
        $post = array(
            'action' => 'get_user'
        );
        $user = $this->connect($this->moodleurl, $post);

        if (is_array($user) && $user) {
            if (!$this->session->isStart()) {
                $this->session->start();
            }
            foreach ($user as $k => $v) {
                $this->session->set($k, $v);
            }
            // 氏名のセット
            if ($params = $this->session->get('params')) {
                $this->session->set('member_id', $params['id']);
                $this->session->set('number', $params['username']);
                $this->session->set('name', $params['firstname'] . ' ' . $params['lastname']);
                return $user;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    /**
     * クイズ情報の取得
     *
     * @param	int	$cmid
     * @return	array
     */
    function getMoodleQuiz($cmid)
    {
        global $CFG, $DB;

        // library
        // Edit by Minh
        if ($CFG->branch < 28)
        {
            require_once($CFG->dirroot . '/mod/quiz/editlib.php');
        } else {
            require_once($CFG->dirroot . '/question/editlib.php');
        }
        // End

        list($thispageurl, $contexts, $cmid, $cm, $quiz, $pagevars) = question_edit_setup('editq', true);

        // error check
        if (! $course = $DB->get_record("course", array("id" => $quiz->course))) {
            print_error("This course doesn't exist");
        }

        // capability check
        require_capability('mod/quiz:manage', $contexts->lowest());

        // get quiz
        if (! $cm = $DB->get_record("course_modules", array("id" => $cmid))) {
            print_error("This course module doesn't exist");
        }

        if (! $course = $DB->get_record("course", array("id" => $cm->course))) {
            print_error("This course doesn't exist");
        }

        require_login($course); // needed to setup proper $COURSE
        $context = context_module::instance($cm->id);
        require_capability('moodle/course:manageactivities', $context);

        if (! $module = $DB->get_record("modules", array("id" => $cm->module))) {
            print_error("This module doesn't exist");
        }

        if (! $form = $DB->get_record($module->name, array("id" => $cm->instance))) {
            print_error("The required instance of this module doesn't exist");
        }

        foreach ($form as $k => $v) {
            $json['params'][$k] = $v;
        }
        $json['params']['cmid'] = $cm->id;
        $json['params']['section'] = $cm->section;
        $json['params']['visible'] = $cm->visible;

        return $json['params'];
    }


    /**
     * 問題カテゴリの取得
     *
     * @param	int	$cmid
     * @return	array
     */
    function getMoodleCategories($cmid)
    {
        global $CFG, $DB;

        // library
        // Edit by Minh
        if ($CFG->branch < 28)
        {
            require_once($CFG->dirroot . '/mod/quiz/editlib.php');
        } else {
            require_once($CFG->dirroot . '/question/editlib.php');
        }
        // End

        // parameters
        list($thispageurl, $contexts, $cmid, $cm, $quiz, $pagevars) = question_edit_setup('editq', true);

        // error check
        if (! $course = $DB->get_record("course", array("id" => $quiz->course))) {
            print_error("This course doesn't exist");
        }

        // capability check
        require_capability('mod/quiz:manage', $contexts->lowest());

        // get question categories
        $contexts = $contexts->having_one_edit_tab_cap('editq');

        // BEGIN: copy from question_category_options on /lib/questionlib.php:1970
        $pcontexts = array();
        foreach($contexts as $context){
            $pcontexts[] = $context->id;
        }
        $contextslist = join($pcontexts, ', ');
        $categories = get_categories_for_contexts($contextslist);
        //$categories = question_add_context_in_key($categories);
        // END: copy

        $prevContextId = null;
        foreach ($categories as $category) {
            if ($prevContextId != $category->contextid) {
                $context = context::instance_by_id($category->contextid)->get_context_name(true, true);
            }
            $json['categories'][$context][$category->id] = array($category->name, $category->contextid);
            $prevContextId = $category->contextid;
        }
        return $json['categories'];
    }

    /**
     * コース情報の取得
     *
     * @return	array
     */
    function getMoodleCourses()
    {
        GLOBAL $CFG;

        // library
        include_once($CFG->dirroot . '/course/lib.php');
        include_once($CFG->dirroot . '/lib/coursecatlib.php');

        // action
        $fields = array(
            'id', 'category', 'sortorder', 'format',
            'shortname', 'fullname', 'idnumber',
        );

        if (has_capability('moodle/site:config', context_system::instance())) {
            // 管理者
            $categories = coursecat::get(0)->get_children();  // Parent = 0   ie top-level categories only
            if (is_array($categories) && count($categories) == 1) {
                /* @var $category coursecat */
                $category   = array_shift($categories);
                $courses    = $category->get_courses(array(
                    'coursecontacts' => true,
                    'sort' => array('fullname' => 1, 'sortorder' => 1)
                ));
            } else {
                $courses    = coursecat::get(0)->get_courses(array(
                    'recursive' => true,
                    'coursecontacts' => true,
                    'sort' => array('fullname' => 1, 'sortorder' => 1)
                ));
            }
            unset($categories);
        } else {
            // 教員
            global $USER;
            $onlyactive = true;
            $courses = enrol_get_users_courses($USER->id, $onlyactive, $fields, 'fullname ASC,visible DESC,sortorder ASC');
        }

        foreach ($courses as $course) {
            if ($course->id == SITEID) {
                continue;
            }
            // 管理権限チェック
            $context = context_course::instance($course->id);

            if (has_capability('moodle/course:manageactivities', $context)) {
                $json['courses'][$course->id] = $course->fullname; // format_string($course->fullname));
            }
        }
        return $json['courses'];
    }

    /**
     * ファイルから行を取得し、CSVフィールドを処理する
     * @param resource handle
     * @param int length
     * @param string delimiter
     * @param string enclosure
     * @return ファイルの終端に達した場合を含み、エラー時にFALSEを返します。
     */
    function parseCsvIncludeBreak($file, $convAfter = 'utf-8', $convBefore = 'sjis-win', $length = null, $d = ',', $e = '"')
    {
        $file = preg_replace("/\r\n|\r|\n/", "\n", $file);

        $fp = tmpfile();
        fputs($fp,$file);
        fseek($fp,0);

        $handle = $fp;
        $returns = array();
        while (($data = $this->_parseCsvIncludeBreak($handle, $convAfter, $convBefore, $length, $d, $e)) !== false) {
            $returns[] = $data;
        }
        fclose($handle);
        return $returns;
    }

    /**
     * ファイルポインタから行を取得し、CSVフィールドを処理する(parseCSVの内部メソッド)
     * @param resource handle
     * @param int length
     * @param string delimiter
     * @param string enclosure
     * @return ファイルの終端に達した場合を含み、エラー時にFALSEを返します。
     */
    function _parseCsvIncludeBreak(&$handle, $convAfter = 'utf-8', $convBefore = 'sjis-win', $length = null, $d = ',', $e = '"')
    {
        $eof = null;
        $d = preg_quote($d);
        $e = preg_quote($e);
        $_line = "";
        while ($eof != true) {
            $_line .= (empty($length) ? fgets($handle) : fgets($handle, $length));
            $_line = str_replace(array("\r\n","\r"), "\n", $_line);
            $itemcnt = preg_match_all('/'.$e.'/', $_line, $dummy);
            if ($itemcnt % 2 == 0) $eof = true;
        }
        $_line = mb_convert_encoding($_line, $convAfter, $convBefore);
        $_csv_line = preg_replace('/(?:\r\n|[\r\n])?$/', $d, trim($_line));
        $_csv_pattern = '/('.$e.'[^'.$e.']*(?:'.$e.$e.'[^'.$e.']*)*'.$e.'|[^'.$d.']*)'.$d.'/';
        preg_match_all($_csv_pattern, $_csv_line, $_csv_matches);
        $_csv_data = $_csv_matches[1];
        for($_csv_i=0;$_csv_i<count($_csv_data);$_csv_i++){
            $_csv_data[$_csv_i]=preg_replace('/^'.$e.'(.*)'.$e.'$/s','$1',$_csv_data[$_csv_i]);
            $_csv_data[$_csv_i]=str_replace($e.$e, $e, $_csv_data[$_csv_i]);
        }
        return empty($_line) ? false : $_csv_data;
    }

    function get_questions_new($quizid)
    {
        global $DB;

        $sql = '
                SELECT qs.questionid FROM {quiz_slots} qs
                WHERE qs.quizid = :quizid
                ORDER BY qs.slot ASC
        ';

        $params = array(
            'quizid' => $quizid,
        );

        return $DB->get_fieldset_sql($sql, $params);
    }

    /**
     * update slots of questions
     *
     * @param object $quiz
     * @param array $post
     * @return void
     */
    public function update_slot_questions($quiz, $post) {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $questionid = isset($post['questionid']) ? $post['questionid'] : 0;
        $pagenumber = isset($post['page_number']) ? $post['page_number'] : 0;
        $mode = isset($post['mode']) ? $post['mode'] : '';
        $previousid = isset($post['previousid']) ? $post['previousid'] : 0;
        $nextid = isset($post['nextid']) ? $post['nextid'] : 0;

        if ($mode == 'u') {
            if ($pagenumber > 1 && !$previousid) {
                $ids = $this->get_ids_of_last_slot($quiz->id, $pagenumber - 1);
                if (is_array($ids) && count($ids) > 0) {
                    $previousid = $ids[0];
                }
            }
        } else {
            $previousid = $nextid;
        }

        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course);
        $course = get_course($quiz->course);
        $quizobj = new quiz($quiz, $cm, $course);
        $structure = $quizobj->get_structure();

        if (!$previousid) {
            $sectionid = $this->get_section_id_by_quiz_id($quiz->id);
            $section = $structure->get_section_by_id($sectionid);
            if ($section->firstslot > 1) {
                $previousid = $structure->get_slot_id_for_slot($section->firstslot - 1);
                $pagenumber = $structure->get_page_number_for_slot($section->firstslot);
            }
        }
        $structure->move_slot($questionid, $previousid, $pagenumber);
    }

    /**
     * Get section id by quiz id
     *
     * @param int $quizid
     * @return int section id
     */
    public function get_section_id_by_quiz_id($quizid) {
        global $DB;
        $sql = 'SELECT id FROM {quiz_sections}
                WHERE quizid = :quizid';
        return  $DB->get_field_sql($sql, array('quizid' => $quizid));
    }

    /**
     * Get ids of quiz_slot
     *
     * @param int $quizid
     * @param int $page
     * @return array ids of quiz_slot
     */
    public function get_ids_of_last_slot($quizid, $page) {
        global $DB;
        $sql = 'SELECT id FROM {quiz_slots}
                WHERE quizid = :quizid AND page = :page
                ORDER BY slot DESC';
        $params = array(
            'quizid' => $quizid,
            'page' => $page,
        );
        return $DB->get_fieldset_sql($sql, $params);
    }

    /**
     * Get questions and slot of quiz
     *
     * @param int $quizid id of quiz.
     * @param int $page page number of quiz.
     * @return mixed array[0] with key include slot number , array[1] only questions value
     */
    public function get_questions_slot($quizid, $page) {
        global $DB;
        $questions = array();
        $questionsnoslot = array();
        $questionslot = $DB->get_records_select('quiz_slots', 'quizid = ? AND page = ? ', array($quizid, $page), 'slot ASC', 'questionid, slot');
        if ($questionslot) {
            foreach ($questionslot as $q) {
                $questions[$q->slot] = $q->questionid;
                $questionsnoslot[] = $q->questionid;
            }
        }
        return array($questions, $questionsnoslot);
    }

    /**
     * change slot of array
     *
     * @param int $slota
     * @param int $slotb
     * @return array after change inddex and value.
     */
    public function change_slot($slota, $slotb, $keys){
        $result = array();
        $result[$keys[$slota - 1]] = $keys[$slotb - 1];
        for ($i = $slotb; $i < $slota; $i++) {
            $result[$keys[$i - 1]] = $keys[$i];
        }
        return $result;
    }

    /**
     * get positions be changed
     *
     * @param array $arrbefore
     * @param array $arrafter
     * @return array positions be changed.
     *         Ex: $arrbefore = array(aa, bb, cc);
     *             $arrafter = array(cc, aa, bb);
     *          => return array(3=>1, 1=>2, 2=>3);
     */
    public function slots_after_reorder($arrbefore, $arrafter) {
        $keys = array_keys($arrbefore);
        $arrbefore = array_values($arrbefore);
        $l = 0;
        $r = count($arrbefore) - 1;
        while ($arrbefore[$l] == $arrafter[$l]) $l++;
        $l++;
        while ($arrbefore[$r] == $arrafter[$r]) $r--;
        $r++;
        return $this->change_slot($r, $l, $keys);
    }

    /**
     * Move item in array
     *
     * @param $array
     * @param $oldpos
     * @param $newpos
     */
    public function array_move(&$array, $oldpos, $newpos) {
        if ($oldpos == $newpos) {
            return;
        }
        array_splice($array, max($newpos,0), 0, array_splice($array, max($oldpos, 0), 1));
    }

    /**
     * Update order question with new order
     *
     * @param $beforesort
     * @param $aftersort
     * @param $quizid
     */
    public function update_order($beforesort, $aftersort, $quizid) {
        $slotreorder = $this->slots_after_reorder($beforesort, $aftersort);
        update_field_with_unique_index('quiz_slots', 'slot', $slotreorder, array('quizid' => $quizid));
    }

    /**
     * Calculate new slot order
     *
     * @param array $beforesort
     * @param array $aftersort
     * @return array|bool
     */
    private function calculate_new_slot_order_for_all_questions($beforesort, $aftersort) {
        if (empty($beforesort) || !is_array($beforesort) || empty($aftersort) || !is_array($aftersort)) {
            return false;
        }
        $order = array();
        $beforesort = array_values($beforesort);
        foreach ($beforesort as $currentslot => $questionid) {
            $newslot = array_search($questionid, array_values($aftersort));
            if ($newslot > -1) {
                $order[$currentslot + 1] = $newslot + 1;
            }
        }
        return $order;
    }

    /**
     * Update question slots
     *
     * @param array $beforesort
     * @param array $aftersort
     * @param int $quizid
     * @return bool
     */
    private function update_slot_for_all_questions($beforesort, $aftersort, $quizid) {
        $slotreorder = $this->calculate_new_slot_order_for_all_questions($beforesort, $aftersort);
        if (!$slotreorder) {
            return false;
        }
        update_field_with_unique_index('quiz_slots', 'slot', $slotreorder, array('quizid' => $quizid));
        return true;
    }

    /**
     * Calculate new slot order for drag question
     *
     * @param array $beforesort
     * @param array $aftersort
     * @return array|bool
     */
    private function calculate_new_slot_order_for_drag_question($beforesort, $aftersort) {
        if (empty($beforesort) || !is_array($beforesort) || empty($aftersort) || !is_array($aftersort)) {
            return false;
        }
        $order = array();
        $beforesort = array_flip($beforesort);
        foreach ($aftersort as $questionid) {
            $order[$questionid] = 0;
            if (isset($beforesort[$questionid])) {
                $order[$questionid] = $beforesort[$questionid];
            }
        }
        return array_combine(array_values($order), array_values($beforesort));
    }

    /**
     * Update question slot after drop drag question
     *
     * @param array $beforesort
     * @param array $aftersort
     * @param int $quizid
     * @return bool
     */
    private function update_order_for_drag_question($beforesort, $aftersort, $quizid) {
        $slotreorder = $this->calculate_new_slot_order_for_drag_question($beforesort, $aftersort);
        if (!$slotreorder) {
            return false;
        }
        update_field_with_unique_index('quiz_slots', 'slot', $slotreorder, array('quizid' => $quizid));
        return true;
    }
}
