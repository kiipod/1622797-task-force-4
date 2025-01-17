<?php

namespace app\models;

use Exception;
use Yii;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use yii\db\Expression;
use DateTime;
use yii\web\IdentityInterface;

/**
 * This is the model class for table "users".
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property int $city_id
 * @property string $date_creation
 * @property int|null $rating
 * @property int|null $grade
 * @property string|null $avatar
 * @property string|null $birthday
 * @property string|null $phone
 * @property string|null $telegram
 * @property string|null $bio
 * @property string $status
 * @property int $is_executor
 * @property int $show_contacts
 * @property int $vk_id
 *
 * @property Files $avatarFile
 * @property Cities $city
 * @property ExecutorCategory[] $executorCategories
 * @property Feedback[] $feedbacks
 * @property Feedback[] $feedbacks0
 * @property Offers[] $responses
 * @property Tasks[] $tasks
 * @property Tasks[] $tasks0
 */
class Users extends ActiveRecord implements IdentityInterface
{
    // Статусы Исполнителя
    private const STATUS_BUSY = 'Занят';
    private const STATUS_FREE = 'Открыт для новых заказов';

    /**
     * {@inheritdoc}
     */
    public static function tableName(): string
    {
        return 'users';
    }

    /**
     * {@inheritdoc}
     */
    public function rules(): array
    {
        return [
            [['name', 'email', 'password', 'city_id', 'is_executor'], 'required'],
            [['city_id', 'rating', 'grade', 'is_executor', 'show_contacts', 'vk_id'], 'integer'],
            [['date_creation', 'birthday'], 'safe'],
            [['bio', 'status', 'avatar'], 'string'],
            [['name', 'email'], 'string', 'max' => 255],
            [['password', 'telegram'], 'string', 'max' => 64],
            [['phone'], 'string', 'max' => 32],
            [['email'], 'unique'],
            [['city_id'], 'exist', 'skipOnError' => true, 'targetClass' => Cities::class,
                'targetAttribute' => ['city_id' => 'id']],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels(): array
    {
        return [
            'id' => 'ID',
            'name' => 'Name',
            'email' => 'Email',
            'password' => 'Password',
            'city_id' => 'City ID',
            'date_creation' => 'Date Creation',
            'rating' => 'Rating',
            'grade' => 'Grade',
            'avatar' => 'Avatar',
            'birthday' => 'Birthday',
            'phone' => 'Phone',
            'telegram' => 'Telegram',
            'bio' => 'Bio',
            'status' => 'Status',
            'is_executor' => 'Is Executor',
            'show_contacts' => 'Show Contacts',
            'vk_id' => 'VK id'
        ];
    }

    /**
     * Gets query for [[City]].
     *
     * @return ActiveQuery
     */
    public function getCity(): ActiveQuery
    {
        return $this->hasOne(Cities::class, ['id' => 'city_id']);
    }

    /**
     * Gets query for [[ExecutorCategories]].
     *
     * @return ActiveQuery
     */
    public function getExecutorCategories(): ActiveQuery
    {
        return $this->hasMany(ExecutorCategory::class, ['user_id' => 'id']);
    }

    /**
     * Gets query for [[Feedbacks]].
     *
     * @return ActiveQuery
     */
    public function getFeedbacks(): ActiveQuery
    {
        return $this->hasMany(Feedback::class, ['customer_id' => 'id']);
    }

    /**
     * Gets query for [[Feedbacks0]].
     *
     * @return ActiveQuery
     */
    public function getExecutorFeedbacks(): ActiveQuery
    {
        return $this->hasMany(Feedback::class, ['executor_id' => 'id']);
    }

    /**
     * Gets query for [[Responses]].
     *
     * @return ActiveQuery
     */
    public function getOffers(): ActiveQuery
    {
        return $this->hasMany(Offers::class, ['executor_id' => 'id']);
    }

    /**
     * Gets query for [[Tasks]].
     *
     * @return ActiveQuery
     */
    public function getCustomerTasks(): ActiveQuery
    {
        return $this->hasMany(Tasks::class, ['customer_id' => 'id']);
    }

    /**
     * Gets query for [[Tasks0]].
     *
     * @return ActiveQuery
     */
    public function getExecutorTasks(): ActiveQuery
    {
        return $this->hasMany(Tasks::class, ['executor_id' => 'id']);
    }

    /** Возвращает выполненные задания исполнителя
     *
     * @return ActiveQuery
     */
    public function getExecutedTasks(): ActiveQuery
    {
        return Tasks::find()
            ->where(['executor_id' => $this->id])
            ->andWhere(['status' => Tasks::STATUS_DONE]);
    }

    /** Возврощает провеленные задачи исполнителя
     *
     * @return ActiveQuery
     */
    public function getFailedTasks(): ActiveQuery
    {
        return Tasks::find()
            ->where(['executor_id' => $this->id])
            ->andWhere(['status' => Tasks::STATUS_FAILED]);
    }

    /** Возвращает в каком статусе находится исполнитель
     *
     * @return string
     */
    public function getExecutorStatus(): string
    {
        if (
            Tasks::findOne(['executor_id' => $this->id,
            'status' => Tasks::STATUS_AT_WORK])
        ) {
            return self::STATUS_BUSY;
        }
        return self::STATUS_FREE;
    }

    /** Возвращает количество откликов исполнителя
     *
     * @return int
     */
    public function getFeedbacksCount(): int
    {
        return $this->getExecutorFeedbacks()->count();
    }

    /** Возвращает дату рождения в человекочитаемом формате
     *
     * @throws Exception
     */
    public function getUserAge(): int
    {
        $now = new DateTime('now');
        $birthday = new DateTime($this->birthday);
        $interval = $now->diff($birthday);

        return $interval->format('%Y');
    }

    /** Метод возвращает занимаемое место в общем рейтинге исполнителей
     *
     * @return int|null
     */
    public function getExecutorRating(): ?int
    {
        $data = Users::find()
            ->leftJoin(Feedback::tableName(), 'feedback.executor_id = users.id')
            ->groupBy(['users.id'])
            ->having(new Expression(
                'AVG(feedback.grade) >= :grade',
                [':grade' => $this->getExecutorGrade()]
            ))
            ->orderBy(['AVG(feedback.grade)' => SORT_DESC])
            ->all();

        for ($i = count($data) - 1; $i >= 0; $i--) {
            if (['$data[$i]->id' => $this->id]) {
                return $i + 1;
            }
        }
        return null;
    }

    /** Метод вычисляет оценку исполнителя по отзывам заказчиков
     *
     * @return float
     */
    public function getExecutorGrade(): float
    {
        $gradeSum = Feedback::find()
            ->where(['executor_id' => $this->id])
            ->sum('grade');
        $feedbackCount = $this->getFeedbacksCount();
        $failedTasks = $this->getFailedTasks()->count();

        if (($feedbackCount + $failedTasks) === 0) {
            return 0;
        }

        return floatval($gradeSum / ($feedbackCount + $failedTasks));
    }

    /** Метод возвращает класс-модель Users
     *
     * @param $id
     * @return Users|IdentityInterface|null
     */
    public static function findIdentity($id): Users|IdentityInterface|null
    {
        return self::findOne($id);
    }

    public static function findIdentityByAccessToken($token, $type = null)
    {
        // TODO: Implement findIdentityByAccessToken() method.
    }

    /** Метод возвращает id текущего пользователя
     *
     * @return int
     */
    public function getId(): int
    {
        return (int)$this->id;
    }

    public function getAuthKey()
    {
        // TODO: Implement getAuthKey() method.
    }

    public function validateAuthKey($authKey)
    {
        // TODO: Implement validateAuthKey() method.
    }

    /** Метод проверяет пароль пользователя на соответствие
     *
     * @param $password
     * @return bool
     */
    public function validatePassword($password): bool
    {
        return Yii::$app->security->validatePassword($password, $this->password);
    }

    /** Метод проверяет оставлял ли исполнитель отклик к конкурентому заданию или нет
     *
     * @param $id
     * @return bool
     */
    public function checkUserOffers($id): bool
    {
        return (bool) Offers::findOne(['task_id' => $id, 'executor_id' => $this->id]);
    }
}
