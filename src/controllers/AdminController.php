<?php

declare(strict_types=1);

namespace app\controllers;

use app\base\BaseController;
use app\models\AdminClockForm;
use app\models\AdminOffForm;
use app\models\Clock;
use app\models\Holiday;
use app\models\Off;
use app\models\Project;
use app\models\TerminalForm;
use app\models\User;
use DateTime;
use DateTimeZone;
use Exception;
use Throwable;
use Yii;
use yii\base\Exception as BaseException;
use yii\base\InvalidConfigException;
use yii\db\ActiveQuery;
use yii\db\Expression;
use yii\db\Query;
use yii\db\StaleObjectException;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\helpers\ArrayHelper;
use yii\helpers\Url;
use yii\web\BadRequestHttpException;
use yii\web\RangeNotSatisfiableHttpException;
use yii\web\Response;
use yii\web\UploadedFile;

use function array_merge;
use function date;
use function fopen;
use function fputcsv;
use function is_numeric;
use function mktime;
use function rewind;
use function round;

/**
 * Class AdminController
 * @package app\controllers
 */
class AdminController extends BaseController
{
    /**
     * @return array
     */
    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'reset' => ['post'],
                    'delete' => ['post'],
                    'promote' => ['post'],
                    'demote' => ['post'],
                    'deactivate' => ['post'],
                    'reactivate' => ['post'],
                    'project-create' => ['post'],
                    'project-delete' => ['post'],
                    'project-archive' => ['post'],
                    'project-bring-back' => ['post'],
                    'off-approve' => ['post'],
                    'off-deny' => ['post'],
                ],
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function remember(): array
    {
        return array_merge(
            parent::remember(),
            [
                'index',
                'projects-manager',
                'projects',
                'history',
                'off',
                'calendar',
                'overview',
                'vacations',
                'terminal-edit',
            ]
        );
    }

    /**
     * @param $action
     * @return bool
     * @throws BadRequestHttpException
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (Yii::$app->user->identity->role !== User::ROLE_ADMIN) {
            Yii::$app->response->redirect(['site/index']);

            return false;
        }

        switch($action->id) {
            case 'session-add':
                if (!Yii::$app->params['adminSessionAdd']) {
                    return false;
                }
                break;
            case 'session-edit':
                if (!Yii::$app->params['adminSessionEdit']) {
                    return false;
                }
                break;
            case 'session-delete':
                if (!Yii::$app->params['adminSessionDelete']) {
                    return false;
                }
                break;
            case 'off-add':
                if (!Yii::$app->params['adminOffTimeAdd']) {
                    return false;
                }
                break;
            case 'off-edit':
                if (!Yii::$app->params['adminOffTimeEdit']) {
                    return false;
                }
                break;
            case 'off-delete':
                if (!Yii::$app->params['adminOffTimeDelete']) {
                    return false;
                }
                break;
        }

        return true;
    }

    /**
     * @return string|Response
     */
    public function actionIndex()
    {
        $users = User::find()->orderBy(['status' => SORT_ASC, 'name' => SORT_ASC])->all();

        return $this->render(
            'index',
            [
                'users' => $users,
            ]
        );
    }

    /**
     * @param null $month
     * @param null $year
     * @param null $id
     * @return string|Response
     */
    public function actionOverview($month = null, $year = null, $id = null)
    {
        [$month, $year, $previousMonth, $previousYear, $nextMonth, $nextYear] = $this->getMonthsAndYears($month, $year);

        if (empty($id)) {
            $user = User::find()->where(['status' => User::STATUS_ACTIVE])->orderBy(['name' => SORT_ASC])->one();
        } else {
            $user = User::find()->where(['id' => $id, 'status' => User::STATUS_ACTIVE])->one();
        }

        if ($user === null) {
            Yii::$app->alert->danger(Yii::t('app', 'Can not find user of given ID.'));
        }

        // query database for the whole month
        $start = Yii::$app->formatter->asDate($year . '-' . $month .'-01', 'yyyy-MM-dd');
        $end = Yii::$app->formatter->asDate($year . '-' . $month .'-01 +1 month', 'yyyy-MM-dd');

        $off = Off::find()->where(
            [
                'and',
                [
                    'or',
                    [
                        'and',
                        ['>=', 'start_at', $start],
                        ['<=', 'start_at', $end],
                    ],
                    [
                        'and',
                        ['>=', 'end_at', $start],
                        ['<=', 'end_at', $end],
                    ],
                    [
                        'and',
                        ['<=', 'start_at', $start],
                        ['>=', 'end_at', $end],
                    ],
                ],
                ['user_id' => $user->id],
            ])
            ->all();
        $clock = Clock::find()->where(
            [
                'and',
                ['<', 'clock_out', (int)Yii::$app->formatter->asTimestamp($end . ' 00:00:00')],
                ['>=', 'clock_in', (int)Yii::$app->formatter->asTimestamp($start . ' 00:00:00')],
                ['user_id' => $user->id],
            ])
            ->orderBy(['clock_in' => SORT_ASC])->all();
        $holiday = Holiday::getHolidaysMonth($month, $year);

        // build array
        $days = [];
        $range = Clock::getDatePeriod($start, $end);
        foreach ($range as $date) {
            $days[Yii::$app->formatter->asDate($date, 'yyyy-MM-dd')]['date'] = $date;
        }
        foreach ($off as $offPeriod) {
            $first = Yii::$app->formatter->asTimestamp($offPeriod->start_at) > Yii::$app->formatter->asTimestamp($start) ?
                $offPeriod->start_at : $start;

            $last = Yii::$app->formatter->asTimestamp($offPeriod->end_at) < Yii::$app->formatter->asTimestamp($end) ?
                Yii::$app->formatter->asDate($offPeriod->end_at . ' +1 day', 'yyyy-MM-dd') : $end;

            $period = Clock::getDatePeriod($first, $last);
            foreach ($period as $date) {
                $days[Yii::$app->formatter->asDate($date, 'yyyy-MM-dd')]['off'][] = $offPeriod;
            }
        }
        foreach ($clock as $clockDay) {
            $days[Yii::$app->formatter->asDate($clockDay->clock_in, 'yyyy-MM-dd')]['clock'][] = $clockDay;
        }
        foreach ($holiday as $item) {
            $date = Yii::$app->formatter->asDate($item->year . '-' . $item->month . '-' . $item->day, 'yyyy-MM-dd');
            $days[$date]['holiday'][] = $item;
        }

        $users = User::find()
            ->where(['status' => User::STATUS_ACTIVE])
            ->indexBy('id')
            ->orderBy(['name' => SORT_ASC])
            ->all();

        return $this->render(
            'overview',
            [
                'months' => Clock::months(),
                'year' => $year,
                'month' => $month,
                'previous' => Clock::months()[$previousMonth],
                'previousYear' => $previousYear,
                'previousMonth' => $previousMonth,
                'next' => Clock::months()[$nextMonth],
                'nextYear' => $nextYear,
                'nextMonth' => $nextMonth,
                'days' => $days,
                'employee' => $user,
                'users' => $users,
            ]
        );
    }

    /**
     * @param string|int|null $year
     * @return string
     */
    public function actionVacations($year = null): string
    {
        [$month, $year, $previousMonth, $previousYear, $nextMonth, $nextYear] = $this->getMonthsAndYears(null, $year);

        $months = [];
        foreach (range(1, 12) as $month) {
            $range = Clock::getMonthPeriod($year, $month);
            $months[] = $range;
        }

        $employees = [];
        $start = $year . '-01-01';
        $end = $year . '-12-31';

        $users = User::find()
            ->where(['status' => User::STATUS_ACTIVE])
            ->indexBy('id')
            ->orderBy(['name' => SORT_ASC])
            ->all();

        $range = Clock::getDatePeriod($start, Yii::$app->formatter->asDate($end . ' +1 day', 'yyyy-MM-dd'));

        $holiday = Holiday::getHolidaysYear($year);
        foreach ($users as $user) {
            foreach ($range as $day) {
                $date = Yii::$app->formatter->asDate($day, 'yyyy-MM-dd');
                $employees[$user->name][$date]['off'] = false;
                $employees[$user->name][$date]['holiday'] = false;
                if (in_array((int)$day->format('N'), Yii::$app->params['weekendDays'])) {
                    $employees[$user->name][$date]['holiday'] = 2;
                }
            }
            foreach ($holiday as $item) {
                $date = Yii::$app->formatter->asDate($item->year . '-' . $item->month . '-' . $item->day, 'yyyy-MM-dd');
                $employees[$user->name][$date]['holiday'] = 1;
            }
        }

        $off = Off::find()->where(
            [
                'or',
                [
                    'and',
                    ['>=', 'start_at', $start],
                    ['<=', 'start_at', $end],
                ],
                [
                    'and',
                    ['>=', 'end_at', $start],
                    ['<=', 'end_at', $end],
                ],
                [
                    'and',
                    ['<=', 'start_at', $start],
                    ['>=', 'end_at', $end],
                ],
            ])
            ->all();

        foreach ($off as $offPeriod) {
            $first = Yii::$app->formatter->asTimestamp($offPeriod->start_at) > Yii::$app->formatter->asTimestamp($start) ?
                $offPeriod->start_at : $start;

            $last = Yii::$app->formatter->asTimestamp($offPeriod->end_at) < Yii::$app->formatter->asTimestamp($end) ?
                Yii::$app->formatter->asDate($offPeriod->end_at . ' +1 day', 'yyyy-MM-dd') : $end;

            $period = Clock::getDatePeriod($first, $last);
            foreach($period as $day) {
                $date = Yii::$app->formatter->asDate($day, 'yyyy-MM-dd');
                $employees[$offPeriod->user->name][$date]['off'] = $offPeriod;
            }
        }

        return $this->render(
            'vacations',
            [
                'employees' => $employees,
                'months' => $months,
                'year' => $year,
            ]
        );
    }

    /**
     * @param string|int $id
     * @return Response
     * @throws BaseException
     */
    public function actionReset($id): Response
    {
        $user = User::findOne($id);

        if ($user === null) {
            Yii::$app->alert->danger(Yii::t('app', 'Can not find user of given ID.'));
        } elseif ($user->status === User::STATUS_DELETED) {
            Yii::$app->alert->danger(Yii::t('app', 'You can not reset password for deactivated user.'));
        } else {
            $user->generatePasswordResetToken();

            if (!$user->save()) {
                Yii::$app->alert->danger(Yii::t('app', 'There was an error while saving user.'));
            } else {
                $mail = Yii::$app->mailer
                    ->compose(
                        [
                            'html' => 'reset-html',
                            'text' => 'reset-text',
                        ],
                        [
                            'user' => $user->name,
                            'link' => Url::to(['site/new-password', 'token' => $user->password_reset_token], true),
                        ]
                    )
                    ->setFrom(Yii::$app->params['email'])
                    ->setTo([$user->email => $user->name])
                    ->setSubject(
                        Yii::t(
                            'app',
                            'Password reset at {company} Timeclock system',
                            ['company' => Yii::$app->params['company']]
                        )
                    );

                if (!$mail->send()) {
                    Yii::$app->alert->danger(
                        Yii::t('app', 'There was an error while sending password reset link email.')
                    );
                } else {
                    Yii::$app->alert->success(Yii::t('app', 'Password reset link email has been sent.'));
                }
            }
        }

        return $this->redirect(['index']);
    }

    /**
     * @param string|int $id
     * @return Response
     * @throws BaseException
     * @throws Throwable
     */
    public function actionDelete($id): Response
    {
        $user = User::findOne($id);

        if ($user === null) {
            Yii::$app->alert->danger(Yii::t('app', 'Can not find user of given ID.'));
        } elseif ((int)$user->id === (int)Yii::$app->user->id) {
            Yii::$app->alert->danger(Yii::t('app', 'You can not delete your own account.'));
        } else {
            Clock::deleteAll(['user_id' => $user->id]);
            if (!$user->delete()) {
                Yii::$app->alert->danger(Yii::t('app', 'There was an error while deleting user.'));
            } else {
                Yii::$app->alert->success(Yii::t('app', 'User has been deleted.'));
            }
        }

        return $this->redirect(['index']);
    }

    /**
     * @param string|int|null $month
     * @param string|int|null $year
     * @return array
     */
    public function getMonthsAndYears($month, $year): array
    {
        if (!is_numeric($month) || $month < 1 || $month > 12) {
            $month = date('n');
        }
        if (!is_numeric($year) || $year < 2018) {
            $year = date('Y');
        }

        $month = (int)$month;
        $year = (int)$year;

        $previousYear = $year;
        $previousMonth = $month - 1;

        if ($previousMonth === 0) {
            $previousMonth = 12;
            $previousYear--;
        }

        $nextYear = $year;
        $nextMonth = $month + 1;

        if ($nextMonth === 13) {
            $nextMonth = 1;
            $nextYear++;
        }

        return [$month, $year, $previousMonth, $previousYear, $nextMonth, $nextYear];
    }

    /**
     * @param string|int|null $week
     * @param int $month
     * @param int $year
     * @return array
     */
    public function getWeekRange($week, int $month, int $year): array
    {
        $firstWeekOfMonth = (int)date('W', mktime(6, 0, 0, $month, 1, $year));
        $lastDayInMonth = (int)date('t', mktime(6, 0, 0, $month, 1, $year));
        $lastWeekOfMonth = (int)date('W', mktime(6, 0, 0, $month, $lastDayInMonth, $year));

        $diffWeek = 1;

        if ($firstWeekOfMonth > $lastWeekOfMonth) {
            $day = $lastDayInMonth;
            $prevCountedWeek = $lastWeekOfMonth;
            while ($firstWeekOfMonth > $lastWeekOfMonth) {
                $lastWeekOfMonth = (int)date('W', mktime(6, 0, 0, $month, --$day, $year));
                if ($prevCountedWeek !== $lastWeekOfMonth) {
                    $diffWeek++;
                    $prevCountedWeek = $lastWeekOfMonth;
                }
            }
        }

        $weeksInMonth = $lastWeekOfMonth - $firstWeekOfMonth + $diffWeek;

        if ($week === null || !is_numeric($week) || $week < 1 || $weeksInMonth < $week) {
            return [null, null, null, $weeksInMonth];
        }

        $inSelectedWeek = false;
        $weekStart = null;
        $weekEnd = null;

        for ($day = 1; $day <= $lastDayInMonth; $day++) {
            if ((int)date('W', mktime(6, 0, 0, $month, $day, $year)) !== $firstWeekOfMonth + $week - 1) {
                if (!$inSelectedWeek) {
                    continue;
                }

                $weekEnd = $day - 1;
                break;
            }

            if (!$inSelectedWeek) {
                $inSelectedWeek = true;
                $weekStart = $day;
            }
        }

        if ($weekEnd === null) {
            $weekEnd = $lastDayInMonth;
        }

        return [(int)$week, $weekStart, $weekEnd, $weeksInMonth];
    }

    /**
     * @param $sessions
     * @param $users
     * @param $year
     * @param $month
     * @param $weekStart
     * @param $weekEnd
     * @return Response
     * @throws RangeNotSatisfiableHttpException
     * @throws InvalidConfigException
     */
    protected function downloadCsv($sessions, $users, $year, $month, $weekStart, $weekEnd): Response
    {
        $content = [['Name', 'Date', 'Project', 'In', 'Out', 'Time']];

        /* @var $session Clock */
        foreach ($sessions as $session) {
            $content[] = [
                $users[$session->user_id]->name,
                Yii::$app->formatter->asDate($session->clock_in, 'yyyy-MM-dd'),
                $session->project_id ? $session->project->name : '',
                Yii::$app->formatter->asTime($session->clock_in, 'HH:mm'),
                $session->clock_out ? Yii::$app->formatter->asTime($session->clock_out, 'HH:mm') : '???',
                $session->clock_out ? round(($session->clock_out - $session->clock_in) / 3600, 2) : '???',
            ];
        }

        $handler = fopen('php://memory', 'rb+');

        foreach ($content as $fields) {
            if (fputcsv($handler, $fields) === false) {
                Yii::$app->alert->danger(Yii::t('app', 'There was an error while preparing file to download.'));

                return $this->refresh();
            }
        }

        rewind($handler);

        $name = 'sessions-' . $year . '-' . ($month < 10 ? '0' : '') . $month . '.csv';
        if ($weekStart !== null) {
            $name = 'sessions-'
                . $year
                . '-'
                . ($month < 10 ? '0' : '')
                . $month
                . '-'
                . ($weekStart < 10 ? '0' : '')
                . $weekStart
                . '--'
                . ($weekEnd < 10 ? '0' : '')
                . $weekEnd
                . '.csv';
        }

        return Yii::$app->response->sendStreamAsFile($handler, $name, ['mimeType' => 'text/csv']);
    }

    /**
     * @param string|int|null $month
     * @param string|int|null $year
     * @param string|int|null $id
     * @param string|int|null $week
     * @param string $started
     * @param string|int $export
     * @return string|Response
     * @throws InvalidConfigException
     */
    public function actionHistory($month = null, $year = null, $id = null, $week = null, $started = null, $export = 0)
    {
        [$month, $year, $previousMonth, $previousYear, $nextMonth, $nextYear] = $this->getMonthsAndYears($month, $year);
        $user = null;
        if (!empty($id)) {
            $user = User::find()->where(['id' => $id, 'status' => User::STATUS_ACTIVE])->one();

            if ($user === null) {
                Yii::$app->alert->danger(Yii::t('app', 'Can not find user of given ID.'));
            }
        }

        [$week, $weekStart, $weekEnd, $weeksInMonth] = $this->getWeekRange($week, $month, $year);

        if ($weekStart === null) {
            $conditions = [
                'and',
                [
                    '>=',
                    'clock_in',
                    (int)Yii::$app->formatter->asTimestamp(
                        $year . '-' . ($month < 10 ? '0' : '') . $month . '-01 00:00:00'
                    ),
                ],
                [
                    '<',
                    'clock_in',
                    (int)Yii::$app->formatter->asTimestamp(
                        $nextYear . '-' . ($nextMonth < 10 ? '0' : '') . $nextMonth . '-01 00:00:00'
                    ),
                ],
            ];
        } else {
            $conditions = [
                'and',
                [
                    '>=',
                    'clock_in',
                    (int)Yii::$app->formatter->asTimestamp(
                        $year . '-' . ($month < 10 ? '0' : '') . $month . '-' . ($weekStart < 10 ? '0' : '') . $weekStart . ' 00:00:00'
                    ),
                ],
                [
                    '<=',
                    'clock_in',
                    (int)Yii::$app->formatter->asTimestamp(
                        $year . '-' . ($month < 10 ? '0' : '') . $month . '-' . ($weekEnd < 10 ? '0' : '') . $weekEnd . ' 23:59:59'
                    ),
                ],
            ];
        }

        if ($user !== null) {
            $conditions[] = ['user_id' => $user->id];
        }

        if ($started === 'false') {
            $conditions[] = ['clock_out' => null];
        }

        $clockQuery = Clock::find()
            ->joinWith(['user' => static function (ActiveQuery $query) {
                $query->andWhere(['status' => User::STATUS_ACTIVE]);
            }], false)
            ->where($conditions);

        $users = User::find()
            ->where(['status' => User::STATUS_ACTIVE])
            ->indexBy('id')
            ->orderBy(['name' => SORT_ASC])
            ->all();

        if ((int)$export === 1) {
            return $this->downloadCsv(
                $clockQuery->orderBy(['user_id' => SORT_ASC, 'clock_in' => SORT_ASC])->all(),
                $users,
                $year,
                $month,
                $weekStart,
                $weekEnd
            );
        }

        return $this->render(
            'history',
            [
                'months' => Clock::months(),
                'year' => $year,
                'month' => $month,
                'previous' => Clock::months()[$previousMonth],
                'previousYear' => $previousYear,
                'previousMonth' => $previousMonth,
                'next' => Clock::months()[$nextMonth],
                'nextYear' => $nextYear,
                'nextMonth' => $nextMonth,
                'clock' => $clockQuery->orderBy(['clock_in' => SORT_DESC])->all(),
                'employee' => $user,
                'users' => $users,
                'week' => $week,
                'weeksInMonth' => $weeksInMonth,
                'weekStart' => $weekStart,
                'weekEnd' => $weekEnd,
                'started' => $started,
            ]
        );
    }

    /**
     * @param string|int|null $month
     * @param string|int|null $year
     * @param string|int|null $id
     * @return string
     * @throws InvalidConfigException
     * @throws Exception
     */
    public function actionCalendar($month = null, $year = null, $id = null): string
    {
        [$month, $year, $previousMonth, $previousYear, $nextMonth, $nextYear] = $this->getMonthsAndYears($month, $year);

        $firstDayInMonth = date(
            'N',
            (int)Yii::$app->formatter->asTimestamp(
                $year . '-' . ($month < 10 ? '0' : '') . $month . '-01 12:00:00'
            )
        );
        $daysInMonth = (int)date(
            't',
            (int)Yii::$app->formatter->asTimestamp(
                $year . '-' . ($month < 10 ? '0' : '') . $month . '-01 12:00:00'
            )
        );

        $user = null;
        if (!empty($id)) {
            $user = User::find()->where(['id' => $id, 'status' => User::STATUS_ACTIVE])->one();

            if ($user === null) {
                Yii::$app->alert->danger(Yii::t('app', 'Can not find user of given ID.'));
            }
        }

        $conditions = [
            'and',
            [
                '>=',
                'clock_in',
                (int)Yii::$app->formatter->asTimestamp($year . '-' . ($month < 10 ? '0' : '') . $month . '-01 00:00:00'),
            ],
            [
                '<',
                'clock_in',
                (int)Yii::$app->formatter->asTimestamp(
                    $nextYear . '-' . ($nextMonth < 10 ? '0' : '') . $nextMonth . '-01 00:00:00'
                ),
            ],
        ];
        if ($user !== null) {
            $conditions[] = ['user_id' => $user->id];
        }
        $clock = Clock::find()
            ->joinWith(['user' => static function (ActiveQuery $query) {
                $query->andWhere(['status' => User::STATUS_ACTIVE]);
            }], false)
            ->where($conditions)
            ->orderBy(['clock_in' => SORT_ASC])
            ->all();

        $conditions = [
            'and',
            ['<', 'start_at', $nextYear . '-' . ($nextMonth < 10 ? '0' : '') . $nextMonth . '-01'],
            ['>=', 'end_at', $year . '-' . ($month < 10 ? '0' : '') . $month . '-01'],
        ];
        if ($user !== null) {
            $conditions[] = ['user_id' => $user->id];
        }
        $off = Off::find()
            ->joinWith(['user' => static function (ActiveQuery $query) {
                $query->andWhere(['status' => User::STATUS_ACTIVE]);
            }], false)
            ->where($conditions)
            ->orderBy(['start_at' => SORT_ASC])
            ->all();

        $users = User::find()->where(['status' => User::STATUS_ACTIVE])->indexBy('id')->all();

        $entries = [];
        foreach ($clock as $session) {
            $day = Yii::$app->formatter->asDate($session->clock_in, 'd');
            if (!array_key_exists($day, $entries)) {
                $entries[$day] = [];
            }

            if (!array_key_exists($session->user_id, $entries[$day])) {
                $entries[$day][$session->user_id] = $users[$session->user_id]->initials;
            }
        }

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $stamp = (int)Yii::$app->formatter->asTimestamp(
                $year . '-' . ($month < 10 ? '0' : '') . $month . '-' . ($day < 10 ? '0' : '') . $day . ' 12:00:00'
            );
            foreach ($off as $dayOff) {
                if ($stamp >= (int)Yii::$app->formatter->asTimestamp($dayOff->start_at . ' 12:00:00')
                    && $stamp <= (int)Yii::$app->formatter->asTimestamp($dayOff->end_at . ' 12:00:00')) {
                    if (!array_key_exists($day, $entries)) {
                        $entries[$day] = [];
                    }

                    if (!array_key_exists($dayOff->user_id, $entries[$day])) {
                        $entries[$day][$dayOff->user_id] = $users[$dayOff->user_id]->initials;
                    }
                }
            }
        }

        return $this->render(
            'calendar',
            [
                'months' => Clock::months(),
                'year' => $year,
                'month' => $month,
                'previous' => Clock::months()[$previousMonth],
                'previousYear' => $previousYear,
                'previousMonth' => $previousMonth,
                'next' => Clock::months()[$nextMonth],
                'nextYear' => $nextYear,
                'nextMonth' => $nextMonth,
                'firstDayInMonth' => $firstDayInMonth,
                'daysInMonth' => $daysInMonth,
                'employee' => $user,
                'users' => $users,
                'holidays' => Holiday::getHolidayDatesMonth($month, $year),
                'entries' => $entries,
            ]
        );
    }

    /**
     * @param string|int $id
     * @return Response
     * @throws Throwable
     */
    public function actionDemote($id): Response
    {
        $user = User::findOne($id);

        if ($user === null) {
            Yii::$app->alert->danger(Yii::t('app', 'Can not find user of given ID.'));
        } elseif ((int)$user->id === (int)Yii::$app->user->id) {
            Yii::$app->alert->danger(Yii::t('app', 'You can not demote your own account.'));
        } else {
            $user->role = User::ROLE_EMPLOYEE;
            if (!$user->save()) {
                Yii::$app->alert->danger(Yii::t('app', 'There was an error while saving user.'));
            } else {
                Yii::$app->alert->success(Yii::t('app', 'User has been demoted.'));
            }
        }

        return $this->redirect(['index']);
    }

    /**
     * @param string|int $id
     * @return Response
     * @throws Throwable
     */
    public function actionPromote($id): Response
    {
        $user = User::findOne($id);

        if ($user === null) {
            Yii::$app->alert->danger(Yii::t('app', 'Can not find user of given ID.'));
        } else {
            $user->role = User::ROLE_ADMIN;
            if (!$user->save()) {
                Yii::$app->alert->danger(Yii::t('app', 'There was an error while saving user.'));
            } else {
                Yii::$app->alert->success(Yii::t('app', 'User has been promoted to admin.'));
            }
        }

        return $this->redirect(['index']);
    }

    /**
     * @param int|string $day
     * @param int|string $month
     * @param int|string $year
     * @param int|string $employee
     * @return string|null
     */
    public function actionDay($day, $month, $year, $employee): ?string
    {
        if (!Yii::$app->request->isAjax) {
            return null;
        }

        if (!is_numeric($month) || $month < 1 || $month > 12) {
            $month = date('n');
        }
        if (!is_numeric($year) || $year < 2018) {
            $year = date('Y');
        }
        if (!is_numeric($day) || $day < 1 || $day > 31) {
            $day = date('j');
        }
        if (!is_numeric($employee)) {
            $employee = 0;
        }

        $date = $year . '-' . ($month < 10 ? '0' : '') . $month . '-' . ($day < 10 ? '0' : '') . $day;

        return $this->renderAjax(
            'day',
            [
                'day' => $day,
                'month' => $month,
                'year' => $year,
                'employee' => (int)$employee,
                'users' => User::find()
                    ->where(['status' => User::STATUS_ACTIVE])
                    ->indexBy('id')
                    ->orderBy(['name' => SORT_ASC])
                    ->all(),
                'clock' => Clock::find()
                    ->joinWith(['user' => static function (ActiveQuery $query) {
                        $query->andWhere(['status' => User::STATUS_ACTIVE]);
                    }], false)
                    ->where(
                        [
                            'and',
                            ['>=', 'clock_in', (int)Yii::$app->formatter->asTimestamp($date . ' 00:00:00')],
                            ['<', 'clock_in', (int)Yii::$app->formatter->asTimestamp($date . ' 23:59:59')],
                        ]
                    )
                    ->orderBy(['clock_in' => SORT_ASC])
                    ->all(),
                'off' => Off::find()
                    ->joinWith(['user' => static function (ActiveQuery $query) {
                        $query->andWhere(['status' => User::STATUS_ACTIVE]);
                    }], false)
                    ->where(
                        [
                            'and',
                            ['<=', 'start_at', $date],
                            ['>=', 'end_at', $date],
                        ]
                    )
                    ->orderBy(['start_at' => SORT_ASC])
                    ->all(),
            ]
        );
    }

    /**
     * @return string|Response
     */
    public function actionProjectsManager()
    {
        return $this->render(
            'projects-manager',
            [
                'projects' => Project::find()->orderBy(['status' => SORT_DESC, 'name' => SORT_ASC])->all(),
                'users' => ArrayHelper::map(
                    User::find()->where(['status' => User::STATUS_ACTIVE])->orderBy(['name' => SORT_ASC])->all(),
                    'id',
                    'name'
                ),
            ]
        );
    }

    /**
     * @return Response
     */
    public function actionProjectCreate(): Response
    {
        $project = new Project();

        if ($project->load(Yii::$app->request->post(), '')) {
            if ($project->save()) {
                Yii::$app->alert->success(Yii::t('app', 'Project has been added.'));
            } else {
                Yii::error(['Error while adding project.', $project->errors]);
                Yii::$app->alert->danger(Yii::t('app', 'Project could not be added.'));
            }
        }

        return $this->redirect(['projects']);
    }

    /**
     * @param string|int $id
     * @return Response
     * @throws Throwable
     * @throws \yii\db\StaleObjectException
     */
    public function actionProjectDelete($id): Response
    {
        /* @var $project Project */
        $project = Project::findOne((int)$id);

        if ($project === null) {
            Yii::$app->alert->danger(Yii::t('app', 'Project could not be found.'));
        } elseif ($project->status !== Project::STATUS_ACTIVE) {
            Yii::$app->alert->danger(Yii::t('app', 'Project can not be deleted.'));
        } elseif (!$project->delete()) {
            Yii::error(['Project deleting error', $project->id]);
            Yii::$app->alert->danger(Yii::t('app', 'There was an error while deleting project.'));
        } else {
            Yii::$app->alert->success(Yii::t('app', 'Project has been deleted permanently.'));
        }

        return $this->redirect(['projects']);
    }

    /**
     * @param string|int $id
     * @return Response
     * @throws Throwable
     */
    public function actionProjectArchive($id): Response
    {
        /* @var $project Project */
        $project = Project::findOne((int)$id);

        if ($project === null) {
            Yii::$app->alert->danger(Yii::t('app', 'Project could not be found.'));
        } elseif ($project->status !== Project::STATUS_LOCKED) {
            Yii::$app->alert->danger(Yii::t('app', 'Project can not be archived.'));
        } else {
            $project->status = Project::STATUS_DELETED;

            if (!$project->save(false, ['status', 'updated_at'])) {
                Yii::error(['Project archiving error', $project->id]);
                Yii::$app->alert->danger(Yii::t('app', 'There was an error while archiving project.'));
            } else {
                Yii::$app->alert->success(Yii::t('app', 'Project has been archived.'));
            }
        }

        return $this->redirect(['projects']);
    }

    /**
     * @param string|int $id
     * @return Response
     * @throws Throwable
     */
    public function actionProjectBringBack($id): Response
    {
        /* @var $project Project */
        $project = Project::findOne((int)$id);

        if ($project === null) {
            Yii::$app->alert->danger(Yii::t('app', 'Project could not be found.'));
        } elseif ($project->status !== Project::STATUS_DELETED) {
            Yii::$app->alert->danger(Yii::t('app', 'Project is not archived.'));
        } else {
            $project->status = Project::STATUS_LOCKED;

            if (!$project->save(false, ['status', 'updated_at'])) {
                Yii::error(['Project bringing back error', $project->id]);
                Yii::$app->alert->danger(Yii::t('app', 'There was an error while bringing project back.'));
            } else {
                Yii::$app->alert->success(Yii::t('app', 'Project has been brought back.'));
            }
        }

        return $this->redirect(['projects']);
    }

    /**
     * @return Response
     */
    public function actionProjectUpdate(): Response
    {
        $data = Yii::$app->request->post();

        $id = ArrayHelper::remove($data, 'id');

        if ($id !== null) {
            $project = Project::findOne((int)$id);

            if ($project === null) {
                Yii::$app->alert->danger(Yii::t('app', 'Project could not be found.'));
            } elseif ($project->load($data, '')) {
                if ($project->validate()) {
                    if (empty($data['assignees'])) {
                        $project->assignees = null;
                    }
                    if ($project->save(false)) {
                        Yii::$app->alert->success(Yii::t('app', 'Project has been updated.'));
                    } else {
                        Yii::error(['Error while updating project.', $project->id, $project->errors]);
                        Yii::$app->alert->danger(Yii::t('app', 'Project could not be updated.'));
                    }
                } else {
                    Yii::error(['Error while updating project.', $project->id, $project->errors]);
                    Yii::$app->alert->danger(Yii::t('app', 'Project could not be updated.'));
                }
            }
        }

        return $this->redirect(['projects-manager']);
    }

    /**
     * @param string|int|null $month
     * @param string|int|null $year
     * @param string|int|null $id
     * @param string|int|null $week
     * @return string
     */
    public function actionProjects($month = null, $year = null, $id = null, $week = null): string
    {
        [$month, $year, $previousMonth, $previousYear, $nextMonth, $nextYear] = $this->getMonthsAndYears($month, $year);

        $user = null;
        if (!empty($id)) {
            $user = User::findOne(['id' => $id, 'status' => User::STATUS_ACTIVE]);

            if ($user === null) {
                Yii::$app->alert->danger(Yii::t('app', 'Can not find user of given ID.'));
            }
        }

        $projects = [];
        $systemProjects = Project::find()->all();

        foreach ($systemProjects as $p) {
            $projects[$p->id] = [
                'name' => $p->name,
                'color' => $p->color,
            ];
        }

        [$week, $weekStart, $weekEnd, $weeksInMonth] = $this->getWeekRange($week, $month, $year);

        $conditions = [
            'and',
            ['is not', 'c.clock_out', null],
            ['is not', 'c.project_id', null],
        ];

        if ($weekStart === null) {
            $conditions[] = [
                '>=',
                'c.clock_in',
                (int)Yii::$app->formatter->asTimestamp($year . '-' . ($month < 10 ? '0' : '') . $month . '-01 00:00:00'),
            ];
            $conditions[] = [
                '<',
                'c.clock_in',
                (int)Yii::$app->formatter->asTimestamp(
                    $nextYear . '-' . ($nextMonth < 10 ? '0' : '') . $nextMonth . '-01 00:00:00'
                ),
            ];
        } else {
            $conditions[] = [
                '>=',
                'c.clock_in',
                (int)Yii::$app->formatter->asTimestamp(
                    $year . '-' . ($month < 10 ? '0' : '') . $month . '-' . ($weekStart < 10 ? '0' : '') . $weekStart . ' 00:00:00'
                ),
            ];
            $conditions[] = [
                '<=',
                'c.clock_in',
                (int)Yii::$app->formatter->asTimestamp(
                    $year . '-' . ($month < 10 ? '0' : '') . $month . '-' . ($weekEnd < 10 ? '0' : '') . $weekEnd . ' 23:59:59'
                ),
            ];
        }

        if ($user !== null) {
            $conditions[] = ['c.user_id' => $user->id];
        }

        $conditions[] = ['u.status' => User::STATUS_ACTIVE];

        $projectSessions = (new Query())
            ->from(Clock::tableName() . ' c')
            ->select(
                [
                    'c.project_id',
                    'c.user_id',
                    new Expression('SUM(c.clock_out - c.clock_in) time'),
                ]
            )
            ->leftJoin(User::tableName() . ' u', 'u.id = c.user_id')
            ->where($conditions)
            ->groupBy(['project_id', 'user_id'])
            ->orderBy(['time' => SORT_DESC])
            ->all();

        return $this->render(
            'projects',
            [
                'months' => Clock::months(),
                'year' => $year,
                'month' => $month,
                'previous' => Clock::months()[$previousMonth],
                'previousYear' => $previousYear,
                'previousMonth' => $previousMonth,
                'next' => Clock::months()[$nextMonth],
                'nextYear' => $nextYear,
                'nextMonth' => $nextMonth,
                'employee' => $user,
                'users' => User::find()
                    ->where(['status' => User::STATUS_ACTIVE])
                    ->indexBy('id')
                    ->orderBy(['name' => SORT_ASC])
                    ->all(),
                'projects' => $projects,
                'time' => $projectSessions,
                'week' => $week,
                'weeksInMonth' => $weeksInMonth,
                'weekStart' => $weekStart,
                'weekEnd' => $weekEnd,
            ]
        );
    }

    /**
     * @param string|int|null $month
     * @param string|int|null $year
     * @param string|int|null $id
     * @param bool|int $wholeYear
     * @return string|Response
     */
    public function actionOff($month = null, $year = null, $id = null, $wholeYear = null)
    {
        if ($wholeYear === null && $year === null && Yii::$app->params['showAllVacations']) {
            // first visit
            $wholeYear = true;
        }

        [$month, $year, $previousMonth, $previousYear, $nextMonth, $nextYear] = $this->getMonthsAndYears($month, $year);

        $user = null;
        if (!empty($id)) {
            $user = User::findOne(['id' => $id, 'status' => User::STATUS_ACTIVE]);

            if ($user === null) {
                Yii::$app->alert->danger(Yii::t('app', 'Can not find user of given ID.'));
            }
        }

        $users = User::find()
            ->where(['status' => User::STATUS_ACTIVE])
            ->indexBy('id')
            ->orderBy(['name' => SORT_ASC])
            ->all();

        if ($wholeYear == true) {
            $conditions = [
                'and',
                ['<', 'start_at', $year + 1 . '-01-01'],
                ['>=', 'end_at', $year . '-01-01'],
            ];
        } else {
            $conditions = [
                'and',
                ['<', 'start_at', $nextYear . '-' . ($nextMonth < 10 ? '0' : '') . $nextMonth . '-01'],
                ['>=', 'end_at', $year . '-' . ($month < 10 ? '0' : '') . $month . '-01'],
            ];
        }

        if ($user !== null) {
            $conditions[] = ['user_id' => $user->id];
        }

        $off = Off::find()
            ->joinWith(['user' => static function (ActiveQuery $query) {
                $query->andWhere(['status' => User::STATUS_ACTIVE]);
            }], false)
            ->where($conditions)
            ->orderBy(['start_at' => SORT_DESC])
            ->all();

        return $this->render(
            'off',
            [
                'months' => Clock::months(),
                'year' => $year,
                'month' => $month,
                'previous' => Clock::months()[$previousMonth],
                'previousYear' => $previousYear,
                'previousMonth' => $previousMonth,
                'next' => Clock::months()[$nextMonth],
                'nextYear' => $nextYear,
                'nextMonth' => $nextMonth,
                'employee' => $user,
                'users' => $users,
                'wholeYear' => $wholeYear,
                'off' => $off,
            ]
        );
    }

    /**
     * @param string|int $id
     * @return Response
     */
    public function actionOffApprove($id): Response
    {
        $off = Off::findOne((int)$id);

        if ($off === null) {
            Yii::$app->alert->danger(Yii::t('app', 'Can not find off-time of given ID.'));
        } elseif (!in_array($off->type, Yii::$app->params['approvableOffTime'])) {
            Yii::$app->alert->danger(Yii::t('app', 'Selected off-time is not approvable.'));
        } else {
            $previous = $off->approved;
            $off->approved = 1;

            if (!$off->save(false, ['approved', 'updated_at'])) {
                Yii::$app->alert->danger(Yii::t('app', 'There was an error while approving off-time.'));
            } else {
                Yii::$app->alert->success(Yii::t('app', 'Off-time has been approved.'));

                if ($previous !== $off->approved) {
                    Off::sendInfoToApplicant($off);
                }
            }
        }

        return $this->goBack(null, true);
    }

    /**
     * @param string|int $id
     * @return Response
     */
    public function actionOffDeny($id): Response
    {
        $off = Off::findOne((int)$id);

        if ($off === null) {
            Yii::$app->alert->danger(Yii::t('app', 'Can not find off-time of given ID.'));
        } elseif (!in_array($off->type, Yii::$app->params['approvableOffTime'])) {
            Yii::$app->alert->danger(Yii::t('app', 'Selected off-time is not approvable.'));
        } else {
            $previous = $off->approved;
            $off->approved = 2;

            if (!$off->save(false, ['approved', 'updated_at'])) {
                Yii::$app->alert->danger(Yii::t('app', 'There was an error while denying off-time.'));
            } else {
                Yii::$app->alert->success(Yii::t('app', 'Off-time has been denied.'));

                if ($previous !== $off->approved) {
                    Off::sendInfoToApplicant($off);
                }
            }
        }

        return $this->goBack(null, true);
    }

    /**
     * @param string|int $id
     * @return Response
     */
    public function actionDeactivate($id): Response
    {
        $user = User::findOne($id);

        if ($user === null) {
            Yii::$app->alert->danger(Yii::t('app', 'Can not find user of given ID.'));
        } elseif ((int)$user->id === (int)Yii::$app->user->id) {
            Yii::$app->alert->danger(Yii::t('app', 'You can not deactivate your own account.'));
        } else {
            $user->status = User::STATUS_DELETED;
            if (!$user->save(false, ['status', 'updated_at'])) {
                Yii::$app->alert->danger(Yii::t('app', 'There was an error while deactivating user.'));
            } else {
                Yii::$app->alert->success(Yii::t('app', 'User has been deactivated.'));
            }
        }

        return $this->redirect(['index']);
    }

    /**
     * @param string|int $id
     * @return Response
     */
    public function actionReactivate($id): Response
    {
        $user = User::findOne($id);

        if ($user === null) {
            Yii::$app->alert->danger(Yii::t('app', 'Can not find user of given ID.'));
        } elseif ((int)$user->id === (int)Yii::$app->user->id) {
            Yii::$app->alert->danger(Yii::t('app', 'You can not reactivate your own account.'));
        } else {
            $user->status = User::STATUS_ACTIVE;
            if (!$user->save(false, ['status', 'updated_at'])) {
                Yii::$app->alert->danger(Yii::t('app', 'There was an error while reactivating user.'));
            } else {
                Yii::$app->alert->success(Yii::t('app', 'User has been reactivated.'));
            }
        }

        return $this->redirect(['index']);
    }

    /**
     * @param string|int|null $month
     * @param string|int|null $year
     * @param string|int|null $day
     * @return string|Response
     * @throws Exception
     */
    public function actionSessionAdd($month = null, $year = null, $day = null)
    {
        if (!is_numeric($month) || $month < 1 || $month > 12) {
            $month = date('n');
        }
        if (!is_numeric($year) || $year < 2018) {
            $year = date('Y');
        }
        if (!is_numeric($day) || $day < 1 || $day > 31) {
            $day = date('j');
        }

        $model = new AdminClockForm(
            new Clock(
                [
                    'project_id' => Yii::$app->user->identity->project_id,
                    'clock_in' => (new DateTime(
                        $year . '-' . ($month < 10 ? '0' : '') . $month . '-' . ($day < 10 ? '0' : '') . $day . date(
                            ' H:i:s'
                        ),
                        new DateTimeZone(Yii::$app->timeZone)
                    )
                    )->getTimestamp(),
                ]
            )
        );
        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            Yii::$app->alert->success(Yii::t('app', 'Session has been saved.'));
            return $this->goBack(null, true);
        }

        $users = ArrayHelper::map(
            User::find()->where(['status' => User::STATUS_ACTIVE, 'role' => [User::ROLE_EMPLOYEE, User::ROLE_ADMIN]])
                ->orderBy(['name' => SORT_ASC])->all(),
            'id',
            'name'
        );

        return $this->render(
            'session-add',
            [
                'model' => $model,
                'projects' => ['' => Yii::t('app', '-- no project --')] + Yii::$app->user->identity->assignedProjects,
                'users' => $users,
            ]
        );
    }

    /**
     * @param string|int|null $month
     * @param string|int|null $year
     * @param string|int|null $day
     * @return string|Response
     * @throws Exception
     */
    public function actionOffAdd($month = null, $year = null, $day = null)
    {
        if (!is_numeric($month) || $month < 1 || $month > 12) {
            $month = date('n');
        }
        if (!is_numeric($year) || $year < 2018) {
            $year = date('Y');
        }
        if (!is_numeric($day) || $day < 1 || $day > 31) {
            $day = date('j');
        }

        $model = new AdminOffForm(
            new Off(
                [
                    'start_at' => $year . '-' . ($month < 10 ? '0' : '') . $month . '-' . ($day < 10 ? '0' : '') . $day,
                    'end_at' => $year . '-' . ($month < 10 ? '0' : '') . $month . '-' . ($day < 10 ? '0' : '') . $day,
                ]
            )
        );
        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            Yii::$app->alert->success(Yii::t('app', 'Off-time has been saved.'));

            return $this->goBack(null, true);
        }

        $users = ArrayHelper::map(
            User::find()->where(['status' => User::STATUS_ACTIVE, 'role' => [User::ROLE_EMPLOYEE, User::ROLE_ADMIN]])
                ->orderBy(['name' => SORT_ASC])->all(),
            'id',
            'name'
        );

        return $this->render(
            'off-add',
            [
                'model' => $model,
                'users' => $users,
            ]
        );
    }

    /**
     * @param string|int $id
     * @param string|int $user_id
     * @return string|Response
     * @throws Exception
     */
    public function actionSessionEdit($id, $user_id)
    {
        $session = Clock::find()->where(
            [
                'id' => (int)$id,
                'user_id' => (int)$user_id,
            ]
        )->one();

        if ($session === null) {
            Yii::$app->alert->danger(Yii::t('app', 'Can not find session of given ID.'));

            return $this->goBack(null, true);
        }

        $model = new AdminClockForm($session);

        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            Yii::$app->alert->success(Yii::t('app', 'Session has been saved.'));

            return $this->goBack(null, true);
        }

        return $this->render(
            'session-edit',
            [
                'session' => $session,
                'model' => $model,
                'projects' => ['' => Yii::t('app', '-- no project --')] + Yii::$app->user->identity->assignedProjects,
            ]
        );
    }

    /**
     * @param string|int $id
     * @param string|int $user_id
     * @return string|Response
     * @throws Exception
     */
    public function actionOffEdit($id, $user_id)
    {
        $off = Off::find()->where(
            [
                'id' => (int)$id,
                'user_id' => (int)$user_id,
            ]
        )->one();

        if ($off === null) {
            Yii::$app->alert->danger(Yii::t('app', 'Can not find off-time of given ID.'));

            return $this->goBack(null, true);
        }

        $model = new AdminOffForm($off);
        if ($model->load(Yii::$app->request->post()) && $model->save()) {
            Yii::$app->alert->success(Yii::t('app', 'Off-time has been saved.'));

            return $this->goBack(null, true);
        }

        return $this->render(
            'off-edit',
            [
                'off' => $off,
                'model' => $model,
                'marked' => Off::getFutureOffDays($off->id, $off->user_id),
            ]
        );
    }

    /**
     * @param string|int $id
     * @param string|int $user_id
     * @return Response
     * @throws StaleObjectException
     * @throws Throwable
     */
    public function actionOffDelete($id, $user_id): Response
    {
        $off = Off::find()->where(
            [
                'id' => (int)$id,
                'user_id' => (int)$user_id,
            ]
        )->one();

        if ($off === null) {
            Yii::$app->alert->danger(Yii::t('app', 'Can not find off-time of given ID.'));
        } elseif (!$off->delete()) {
            Yii::$app->alert->danger(Yii::t('app', 'There was an error while deleting off-time.'));
        } else {
            Yii::$app->alert->success(Yii::t('app', 'Off-time has been deleted.'));
        }

        return $this->goBack(null, true);
    }

    /**
     * @param string|int $id
     * @param string|int $user_id
     * @param bool $stay
     * @return Response
     * @throws StaleObjectException
     * @throws Throwable
     */
    public function actionSessionDelete($id, $user_id, bool $stay = true): Response
    {
        $clock = Clock::find()->where(
            [
                'id' => (int)$id,
                'user_id' => (int)$user_id,
            ]
        )->one();

        if ($clock === null) {
            Yii::$app->alert->danger(Yii::t('app', 'Can not find session of given ID.'));
        } elseif (!$clock->delete()) {
            Yii::$app->alert->danger(Yii::t('app', 'There was an error while deleting session.'));
        } else {
            Yii::$app->alert->success(Yii::t('app', 'Session has been deleted.'));
        }

        return $this->goBack(null, $stay);
    }

    /**
     * @param $id
     * @return string
     */
    public function actionTerminalEdit($id)
    {
        $user = User::findOne($id);
        if ($user === null) {
            Yii::$app->alert->danger(Yii::t('app', 'Can not find user of given ID.'));
            return $this->goBack();
        }

        $model = new TerminalForm($user);

        if ($model->load(Yii::$app->request->post())) {
            if (!$model->delete) {
                $model->imageFile = UploadedFile::getInstance($model, 'imageFile');
            }
            if ($model->save()) {
                Yii::$app->alert->success(Yii::t('app', 'Terminal data has been saved.'));
                return $this->goBack();
            } else {
                Yii::$app->alert->success(Yii::t('app', 'Error while saving terminal data.'));
            }
        }

        return $this->render('terminal-edit', ['model' => $model]);
    }
}
